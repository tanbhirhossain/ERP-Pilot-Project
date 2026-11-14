<?php

/**
 * Attendance regularization — the "not working" report, taken apart.
 *
 * Nothing in the suite had ever driven this feature end to end (no test mentioned it), which is how it
 * shipped with three independent ways of doing nothing:
 *
 *  1. the form validated four types while the picker offered eight and the column is an `enum` of those
 *     eight — so five of the eight selections were rejected, and `Short Hours` was accepted by the
 *     validator and could not be stored at all;
 *  2. the page rendered no validation errors, so a rejected request and a dead button looked identical;
 *  3. the service's `DomainException` (duplicate request, re-review of a decided one) escaped the
 *     controller as a 500.
 *
 * These tests pin the contract that fixes all three: one type list (`AttendanceRegularization::TYPES`)
 * shared by picker, validator and schema; a refusal that comes back as a readable flash on a redirect;
 * and an approval that actually rewrites the attendance day *whatever* type it was filed under.
 *
 * Helpers are prefixed `reg*`.
 */

use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

const REG_DATE = '2026-11-04';

function regUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "Reg Role {$salt}", 'guard_name' => 'web']);

    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }

    // forceFill, not create(): `email_verified_at` is not fillable and an unverified user never reaches
    // the route, so the assertion would be about the auth middleware instead of about regularization.
    $user = User::forceCreate(['name' => "Reg {$salt}", 'email' => "reg{$salt}@example.test", 'password' => bcrypt('secret-password')]);
    $user->assignRole($role);

    return $user;
}

function regManager(int $salt = 11): User
{
    return regUser(['hrm.regularizations.view', 'hrm.regularizations.manage'], $salt);
}

function regApprover(int $salt = 12): User
{
    return regUser(['hrm.regularizations.view', 'hrm.regularizations.manage', 'hrm.regularizations.approve'], $salt);
}

function regPayload(Employee $e, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $e->employee_id,
        'duty_date' => REG_DATE,
        'regularization_type' => 'Missing In',
        'requested_in_time' => '09:00',
        'requested_out_time' => '',
        'reason' => 'Biometric reader rejected my card at the gate.',
    ], $overrides);
}

function regEmployee(): Employee
{
    return test()->createEmployee();
}

dataset('regularization types', function () {
    foreach (AttendanceRegularization::TYPES as $i => $type) {
        yield $type => [$type, $i];
    }
});

test('every type the picker offers can actually be filed', function (string $type, int $salt) {
    $e = regEmployee();

    test()->actingAs(regManager(100 + $salt))
        ->post(route('hrm.regularizations.store'), regPayload($e, [
            'regularization_type' => $type,
            'requested_in_time' => str_contains($type, 'Out') ? '' : '09:00',
            'requested_out_time' => str_contains($type, 'Out') ? '18:00' : '',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    test()->assertDatabaseHas('hrm_attendance_regularizations', [
        'employee_id' => $e->employee_id,
        'regularization_type' => $type,
        'status' => 'Pending',
    ]);
})->with('regularization types');

test('a type the column cannot hold is refused with a field error, not stored', function () {
    $e = regEmployee();

    // `Short Hours` used to be the validator's own invention: it passed `in:` and then had nowhere to go
    // in the enum. Now the validator only knows the schema's list, so this is a normal field error.
    test()->actingAs(regManager())
        ->post(route('hrm.regularizations.store'), regPayload($e, ['regularization_type' => 'Short Hours']))
        ->assertSessionHasErrors('regularization_type');

    test()->assertDatabaseCount('hrm_attendance_regularizations', 0);
});

test('the page is handed the same list the server validates against', function () {
    // Inertia props live in the `page` view data, not the view's own, so `assertViewHas` would miss them.
    $props = $this->actingAs(regManager())->get(route('hrm.regularizations.index'))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['types'])->toBe(AttendanceRegularization::TYPES)
        // A manager files and reads, but does not adjudicate — see the 403 test below.
        ->and($props['canManage'])->toBeTrue()
        ->and($props['canApprove'])->toBeFalse();

    $approverProps = $this->actingAs(regApprover(13))->get(route('hrm.regularizations.index'))
        ->assertOk()->viewData('page')['props'];

    expect($approverProps['canApprove'])->toBeTrue();
});

test('a duplicate request comes back as a message instead of a 500', function () {
    $e = regEmployee();
    $manager = regManager();

    $this->actingAs($manager)->post(route('hrm.regularizations.store'), regPayload($e))->assertRedirect();

    // Same employee, same date, same type, still Pending. The service refuses it and the database has a
    // unique index behind that refusal, so an uncaught DomainException was both a crash and a near-miss.
    $this->actingAs($manager)
        ->post(route('hrm.regularizations.store'), regPayload($e, ['reason' => 'Filing it twice, by mistake.']))
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => $message !== '')
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('hrm_attendance_regularizations', 1);
});

test('reviewing needs the approve permission, which the Manager role deliberately lacks', function () {
    $e = regEmployee();
    $manager = regManager();

    $this->actingAs($manager)->post(route('hrm.regularizations.store'), regPayload($e));
    $reg = AttendanceRegularization::first();

    $this->actingAs($manager)
        ->post(route('hrm.regularizations.review', $reg), ['decision' => 'Approved'])
        ->assertForbidden();

    $this->assertDatabaseHas('hrm_attendance_regularizations', ['id' => $reg->id, 'status' => 'Pending']);
});

test('an approval rewrites the day whatever type it was filed under', function () {
    $e = regEmployee();
    $approver = regApprover();

    // A day that was punched, but punched late. `Late Correction` used to be approved in name only: the
    // punch-patching was gated on `Missing In`/`Missing Out`, so the record stayed exactly as it was.
    AttendanceDaily::query()->create([
        'employee_id' => $e->employee_id,
        'duty_date' => REG_DATE,
        'primary_punch_in' => REG_DATE.' 10:42:00',
        'primary_punch_out' => REG_DATE.' 18:06:00',
        'primary_worked_hours' => 7.4,
        'status' => 'Late',
    ]);

    $this->actingAs($approver)->post(route('hrm.regularizations.store'), regPayload($e, [
        'regularization_type' => 'Late Correction',
        'requested_in_time' => '09:05',
        'reason' => 'The gate queue ran late; supervisor confirms I was there at 09:05.',
    ]));

    $reg = AttendanceRegularization::first();

    $reviewed = $this->actingAs($approver)
        ->post(route('hrm.regularizations.review', $reg), ['decision' => 'Approved', 'review_remarks' => 'Verified with the supervisor.']);

    $reviewed->assertRedirect()->assertSessionHas('success');

    $daily = AttendanceDaily::query()
        ->where('employee_id', $e->employee_id)->whereDate('duty_date', REG_DATE)->firstOrFail();

    expect((string) $daily->primary_punch_in)->toContain('09:05')
        ->and((float) $daily->primary_worked_hours)->toBe(9.02)
        ->and((bool) $daily->is_regularized)->toBeTrue()
        ->and($daily->regularization_id)->toBe($reg->id)
        // The status is the engine's business: an approved missing-punch request is not an amnesty for
        // being late, so the day keeps `Late` and the flash says so instead of claiming Present.
        ->and($daily->status)->toBe('Late');

    $this->assertDatabaseHas('hrm_attendance_regularizations', ['id' => $reg->id, 'status' => 'Approved']);

    // The flash has to describe the day as it now is. It used to promise "patched and recalculated to
    // Present" for every approval, which was wrong here and the reviewer would have believed it.
    $reviewed->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'Late') && ! str_contains($m, 'Present'));
});

test('a day with no record at all is created from the approved times', function () {
    $e = regEmployee();
    $approver = regApprover();

    $this->actingAs($approver)->post(route('hrm.regularizations.store'), regPayload($e, [
        'requested_in_time' => '09:00',
        'requested_out_time' => '17:30',
        'reason' => 'I forgot to punch out at the site office, the client log proves it.',
    ]));

    $reg = AttendanceRegularization::first();

    $this->actingAs($approver)
        ->post(route('hrm.regularizations.review', $reg), ['decision' => 'Approved'])
        ->assertRedirect();

    $daily = AttendanceDaily::query()
        ->where('employee_id', $e->employee_id)->whereDate('duty_date', REG_DATE)->firstOrFail();

    expect($daily->status)->toBe('Present')
        ->and((float) $daily->primary_worked_hours)->toBe(8.5)
        ->and((bool) $daily->is_regularized)->toBeTrue();
});

test('only a missing out-punch leaves the day short, and the message admits it', function () {
    $e = regEmployee();
    $approver = regApprover();

    // `Missing In` with an out time and no in time: creating the record must not pretend the day is
    // Present, which is what the unconditional `'status' => 'Present'` did.
    $this->actingAs($approver)->post(route('hrm.regularizations.store'), regPayload($e, [
        'requested_in_time' => '',
        'requested_out_time' => '18:00',
        'reason' => 'My in-punch at 09:00 was never captured by the old device.',
    ]));

    $reg = AttendanceRegularization::first();

    $reviewed = $this->actingAs($approver)->post(route('hrm.regularizations.review', $reg), ['decision' => 'Approved']);

    $daily = AttendanceDaily::query()
        ->where('employee_id', $e->employee_id)->whereDate('duty_date', REG_DATE)->firstOrFail();

    expect($daily->status)->toBe('Missing In')
        ->and((float) $daily->primary_worked_hours)->toBe(0.0);

    $reviewed->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'Missing In'));
});

test('a decided request cannot be reviewed or cancelled again', function () {
    $e = regEmployee();
    $approver = regApprover();

    $this->actingAs($approver)->post(route('hrm.regularizations.store'), regPayload($e));
    $reg = AttendanceRegularization::first();

    $this->actingAs($approver)->post(route('hrm.regularizations.review', $reg), ['decision' => 'Approved']);

    // Re-reviewing used to be a 500 page (the service throws, the controller let it out).
    $this->actingAs($approver)
        ->post(route('hrm.regularizations.review', $reg), ['decision' => 'Rejected'])
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionHasNoErrors();

    $this->actingAs($approver)
        ->post(route('hrm.regularizations.cancel', $reg))
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->assertDatabaseHas('hrm_attendance_regularizations', ['id' => $reg->id, 'status' => 'Approved']);
});

test('a manager can withdraw a pending request of their own', function () {
    $e = regEmployee();
    $manager = regManager();

    $this->actingAs($manager)->post(route('hrm.regularizations.store'), regPayload($e));
    $reg = AttendanceRegularization::first();

    $this->actingAs($manager)
        ->post(route('hrm.regularizations.cancel', $reg))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('hrm_attendance_regularizations', ['id' => $reg->id, 'status' => 'Cancelled']);
});

test('a reason shorter than the audit trail needs is rejected on the field', function () {
    $e = regEmployee();

    $this->actingAs(regManager())
        ->post(route('hrm.regularizations.store'), regPayload($e, ['reason' => 'oops']))
        ->assertSessionHasErrors('reason');
});

test('the queue list names the reviewer instead of throwing on a missing relation', function () {
    // Reported 2026-11-10: `RelationNotFoundException: Call to undefined relationship [reviewer] on model
    // [Modules\HRM\Models\AttendanceRegularization]`. `index()` has always eager-loaded
    // `reviewer:id,name` — the column (`reviewed_by`) and the relation were written by different hands,
    // and nothing in the suite had ever GETed this page with a row in it, so the 500 shipped.
    $e = regEmployee();
    $manager = regManager(31);
    $approver = regApprover(32);

    $this->actingAs($manager)->post(route('hrm.regularizations.store'), regPayload($e));
    $reg = AttendanceRegularization::firstOrFail();

    $this->actingAs($approver)->post(route('hrm.regularizations.review', $reg), [
        'decision' => 'Approved',
        'remarks' => 'Gate log confirms 09:02 entry.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($approver)
        ->get(route('hrm.regularizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::Regularizations/Index')
            ->where('regularizations.data.0.status', 'Approved')
            ->where('regularizations.data.0.reviewer.name', 'Reg 32'));

    // An unreviewed request has no reviewer — the eager load must yield null, not an error.
    $this->actingAs($manager)
        ->post(route('hrm.regularizations.store'), regPayload($e, ['duty_date' => '2026-11-05', 'regularization_type' => 'Missing Out']));

    $this->actingAs($manager)
        ->get(route('hrm.regularizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('regularizations.data', 2));
});

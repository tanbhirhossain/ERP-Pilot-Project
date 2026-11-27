<?php

/**
 * The web desks walk the same chain as the mobile app.
 *
 * The engine, its chains and its pings were built for the API first; the web
 * leave desk and the roster overtime desk approved past them — a single click
 * finalized, nobody was told, and the CEO never saw a thing. Pinned contracts:
 *
 *  1. A web leave walks all five stages (Supervisor → HR → DGM → GM → CEO):
 *     each desk is pinged in turn, the comments travel, the applicant is told
 *     at the end, and deciding from the wrong stage is refused with the desk
 *     the application actually waits on.
 *  2. A web overtime walks its four stages the same way, and the final stage
 *     still writes exactly one payroll cutlist row — the money path moves once.
 *  3. A stage that resolves to nobody (an unmapped supervisor) falls back to
 *     the desk permission holders instead of deadlocking the request.
 *  4. The CEO approves leave and reads everything else: every `*.view`
 *     permission converges onto the role on re-seed, and no write permission.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\RosterOvertimeService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ApprovalChainSeeder::class);

    $mkUser = function (string $email, ?string $role = null): User {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'email_verified_at' => now(),
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    };

    $mkEmployee = function (User $user, string $code): void {
        DB::table('employees')->insert([
            'employee_id' => $code,
            'user_id' => $user->id,
            'first_name' => $code,
            'status' => 'Active',
            'joining_date' => '2024-06-01',
            'is_active' => true,
            'allow_overtime' => 1,
        ]);
    };

    $this->applicant = $mkUser('ww-applicant@example.com');
    $mkEmployee($this->applicant, 'WW-A1');
    Permission::firstOrCreate(['name' => 'hrm.leaves.manage', 'guard_name' => 'web']);
    $this->applicant->givePermissionTo('hrm.leaves.manage');

    $this->supervisor = $mkUser('ww-supervisor@example.com', 'Supervisor');
    $mkEmployee($this->supervisor, 'WW-S1');
    DB::table('employee_supervisors')->insert([
        'employee_id' => 'WW-A1',
        'supervisor_id' => $this->supervisor->id,
        'supervisor_type' => 'Supervisor',
    ]);

    $this->hr = $mkUser('ww-hr@example.com', 'HR Admin');
    $this->dgm = $mkUser('ww-dgm@example.com', 'DGM HR Admin');
    $this->gm = $mkUser('ww-gm@example.com', 'General Manager');
    $this->ceo = $mkUser('ww-ceo@example.com', 'CEO');
    $this->outsider = $mkUser('ww-outsider@example.com');

    $this->lone = $mkUser('ww-lone@example.com');
    $mkEmployee($this->lone, 'WW-L1');

    $this->leaveTypeId = DB::table('leave_types')->insertGetId([
        'name' => 'Annual', 'code' => 'AN', 'days_per_year' => 14,
        'is_paid' => true, 'is_active' => true,
    ]);
});

/** File a two-day leave through the web form. */
function wwApplyLeave(object $t, string $employeeId = 'WW-A1', int $offset = 7): LeaveRequest
{
    $from = now()->addDays($offset)->toDateString();
    $t->actingAs($t->applicant)->post('/hrm/leaves', [
        'employee_id' => $employeeId,
        'leave_type_id' => $t->leaveTypeId,
        'from_date' => $from,
        'to_date' => now()->addDays($offset + 1)->toDateString(),
        'reason' => 'Family trip',
    ])->assertRedirect()->assertSessionHasNoErrors();

    return LeaveRequest::query()->where('employee_id', $employeeId)->whereDate('from_date', $from)->firstOrFail();
}

/** Whether any bell row for a user contains a fragment (ids are UUIDs — unordered). */
function wwNoteHas(int $userId, string $needle): bool
{
    return DB::table('notifications')->where('notifiable_id', $userId)->pluck('data')
        ->contains(fn ($data): bool => str_contains((string) $data, $needle));
}

/** One attendance day with overtime, as the pipeline would have written it. */
function wwDaily(string $employeeId, string $date, float $ot = 3.5, float $grand = 11.5): void
{
    DB::table('attendance_dailies')->insert([
        'employee_id' => $employeeId,
        'duty_date' => $date,
        'primary_worked_hours' => $grand - $ot,
        'total_overtime_hours' => $ot,
        'grand_total_hours' => $grand,
        'status' => 'Present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a web leave walks all five stages with a bell ping on every desk', function (): void {
    $leave = wwApplyLeave($this);

    // The snapshot carries the chain labels, and the supervisor was pinged.
    $steps = json_decode(DB::table('leave_request_workflows')->where('leave_request_id', $leave->id)->value('steps'), true);
    expect($steps)->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager', 'CEO']);
    expect(wwNoteHas($this->supervisor->id, 'New leave application'))->toBeTrue();

    // HR holds the permission but not the stage: refused, and told whose it is.
    $this->actingAs($this->hr)->post("/hrm/leaves/{$leave->id}/approve")
        ->assertRedirect()->assertSessionHas('error');
    expect($leave->fresh()->status)->toBe('Pending');

    // No permission at all: the route middleware answers 403.
    $this->actingAs($this->outsider)->post("/hrm/leaves/{$leave->id}/approve")->assertStatus(403);

    $walk = [
        [$this->supervisor, 'HR and Admin'],
        [$this->hr, 'DGM HR and Admin'],
        [$this->dgm, 'General Manager'],
        [$this->gm, 'CEO'],
    ];

    foreach ($walk as $index => [$user, $next]) {
        $this->actingAs($user)->post("/hrm/leaves/{$leave->id}/approve", ['comments' => "Web OK stage {$index}"])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        expect($leave->fresh()->status)->toBe('Pending');

        $nextUser = [$this->hr, $this->dgm, $this->gm, $this->ceo][$index];
        expect(wwNoteHas($nextUser->id, "Leave moved to {$next}"))->toBeTrue();
    }

    // The CEO finalizes; the comments travelled the whole way.
    $this->actingAs($this->ceo)->post("/hrm/leaves/{$leave->id}/approve", ['comments' => 'Final web OK'])
        ->assertRedirect()->assertSessionHas('success');
    expect($leave->fresh()->status)->toBe('Approved');

    $actions = DB::table('leave_approval_actions')->where('leave_request_id', $leave->id)->orderBy('stage_index')->get();
    expect($actions)->toHaveCount(5);
    expect($actions->pluck('stage_name')->all())->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager', 'CEO']);
    expect($actions->pluck('actor_id')->unique()->count())->toBe(5);
    expect($actions[0]->comments)->toBe('Web OK stage 0');
    expect($actions[4]->comments)->toBe('Final web OK');

    // The applicant was told the walk is over.
    expect(wwNoteHas($this->applicant->id, 'Final approval complete'))->toBeTrue();
});

test('a web leave rejection tells the applicant why', function (): void {
    $leave = wwApplyLeave($this);

    $this->actingAs($this->supervisor)->post("/hrm/leaves/{$leave->id}/reject", ['comments' => 'Web blackout week'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($leave->fresh()->status)->toBe('Rejected');
    expect(wwNoteHas($this->applicant->id, 'Web blackout week'))->toBeTrue();

    // ... and a rejection without a reason is not taken.
    $second = wwApplyLeave($this, 'WW-A1', 21);
    $this->actingAs($this->supervisor)->post("/hrm/leaves/{$second->id}/reject")
        ->assertRedirect()->assertSessionHasErrors('comments');
    expect($second->fresh()->status)->toBe('Pending');
});

test('a web overtime walks four stages and still writes one cutlist row', function (): void {
    $date = now()->subDays(3)->toDateString();
    wwDaily('WW-A1', $date);

    $result = app(RosterOvertimeService::class)->syncFromAttendance('Overtime', now()->subDays(5)->toDateString(), now()->subDay()->toDateString());
    expect($result['created'])->toBe(1);
    expect(wwNoteHas($this->supervisor->id, 'New Overtime claim'))->toBeTrue();

    $row = RosterOvertime::query()->where('employee_id', 'WW-A1')->firstOrFail();

    // Wrong stage, right permission: refused with the desk it waits on.
    $this->actingAs($this->hr)->post("/hrm/overtimes/{$row->id}/approve", ['hours' => 3.0])
        ->assertRedirect()->assertSessionHas('error');
    expect($row->fresh()->status)->toBe('Pending');

    $walk = [$this->supervisor, $this->hr, $this->dgm];
    $nextUsers = [$this->hr, $this->dgm, $this->gm];
    $nextLabels = ['HR and Admin', 'DGM HR and Admin', 'General Manager'];

    foreach ($walk as $index => $user) {
        $this->actingAs($user)->post("/hrm/overtimes/{$row->id}/approve", ['hours' => 3.0, 'comments' => "Web OT OK {$index}"])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        expect($row->fresh()->status)->toBe('Pending');
        expect(wwNoteHas($nextUsers[$index]->id, "Overtime moved to {$nextLabels[$index]}"))->toBeTrue();
    }

    // GM finalizes with adjusted hours: one cutlist row, the reviewed hours.
    $this->actingAs($this->gm)->post("/hrm/overtimes/{$row->id}/approve", ['hours' => 2.5, 'comments' => 'Gate log shows 2.5h'])
        ->assertRedirect()->assertSessionHas('success');

    $row->refresh();
    expect($row->status)->toBe('Approved')->and((float) $row->hours)->toBe(2.5);

    $decisions = DB::table('payroll_attendance_duty_decisions')->where('roster_overtime_id', $row->id)->get();
    expect($decisions)->toHaveCount(1);
    expect((float) $decisions[0]->approved_hours)->toBe(2.5)
        ->and($decisions[0]->decision)->toBe('approved');

    $actions = DB::table('overtime_approval_actions')->where('overtime_id', $row->id)->orderBy('stage_index')->get();
    expect($actions)->toHaveCount(4);
    expect($actions->pluck('stage_name')->all())->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager']);

    expect(wwNoteHas($this->applicant->id, 'Final approval complete'))->toBeTrue();
});

test('a web overtime rejection records the trail and tells the applicant', function (): void {
    $date = now()->subDays(4)->toDateString();
    wwDaily('WW-A1', $date);
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', now()->subDays(5)->toDateString(), now()->subDay()->toDateString());
    $row = RosterOvertime::query()->where('employee_id', 'WW-A1')->firstOrFail();

    $this->actingAs($this->supervisor)->post("/hrm/overtimes/{$row->id}/reject", ['reason' => 'No web gate entry'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('Rejected');

    $action = DB::table('overtime_approval_actions')->where('overtime_id', $row->id)->first();
    expect($action->decision)->toBe('Rejected')->and($action->stage_name)->toBe('Supervisor');
    expect(wwNoteHas($this->applicant->id, 'No web gate entry'))->toBeTrue();

    // The payroll cutlist still records the refusal, as before.
    expect(DB::table('payroll_attendance_duty_decisions')->where('roster_overtime_id', $row->id)->where('decision', 'rejected')->count())->toBe(1);
});

test('a stage nobody holds falls back to the desk, not to a deadlock', function (): void {
    // WW-L1 has no supervisor mapping: the Supervisor stage resolves to nobody.
    $leave = wwApplyLeave($this, 'WW-L1');

    // HR holds the desk permission, so the walk can start.
    $this->actingAs($this->hr)->post("/hrm/leaves/{$leave->id}/approve")
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $stage = app(\Modules\HRM\Services\ApprovalEngine::class)->leaveStage($leave->fresh());
    expect($stage['label'])->toBe('HR and Admin');
});

test('bulk approve clears only my stage and reports the rest', function (): void {
    wwDaily('WW-A1', now()->subDays(3)->toDateString());
    wwDaily('WW-A1', now()->subDays(4)->toDateString());
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', now()->subDays(5)->toDateString(), now()->subDay()->toDateString());
    $rows = RosterOvertime::query()->where('employee_id', 'WW-A1')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    // The first row is already waiting on HR; the second still on the supervisor.
    $this->actingAs($this->supervisor)->post("/hrm/overtimes/{$rows[0]->id}/approve", ['hours' => 3.0])->assertSessionHasNoErrors();

    $this->actingAs($this->hr)->post('/hrm/overtimes/bulk-approve', ['ids' => [$rows[0]->id, $rows[1]->id]])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    expect((int) DB::table('overtime_request_workflows')->where('overtime_id', $rows[0]->id)->value('current_stage'))->toBe(2);
    // The skipped row was never touched: not even a workflow row exists for it.
    expect(DB::table('overtime_request_workflows')->where('overtime_id', $rows[1]->id)->exists())->toBeFalse();
});

test('the CEO reads everything but writes nothing', function (): void {
    // A view permission from a module the chain seeder never heard of still
    // converges onto the CEO on re-seed — the live mechanism, pinned.
    Permission::firstOrCreate(['name' => 'payroll.sheets.view', 'guard_name' => 'web']);
    $this->seed(ApprovalChainSeeder::class);

    $ceo = $this->ceo->fresh();
    expect($ceo->can('hrm.leaves.approve'))->toBeTrue()
        ->and($ceo->can('payroll.sheets.view'))->toBeTrue()
        ->and($ceo->can('hrm.overtimes.view'))->toBeTrue()
        ->and($ceo->can('hrm.leaves.manage'))->toBeFalse()
        ->and($ceo->can('hrm.overtimes.manage'))->toBeFalse();
});

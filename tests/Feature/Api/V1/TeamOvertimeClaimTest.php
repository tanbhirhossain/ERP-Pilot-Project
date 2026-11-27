<?php

/**
 * On-behalf overtime, the missing half of the team surface.
 *
 * Supervisors could file LEAVE for a report (POST team/leaves/apply) but had
 * no endpoint for OT/Extra-Duty/Holiday claims — POST team/overtimes/claim
 * closes that gap with the same scope rule: direct reports only, HR/DGM/GM/
 * CEO/Admins everyone, anything else 403.
 *
 * It also pins the dual-role answer: a supervisor who IS an employee files for
 * themselves through the self-service endpoints (their login resolves to their
 * own code) and for their people through the team endpoints — one login, both.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;

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
        ]);
    };

    $this->report = $mkUser('toc-report@example.com');
    $mkEmployee($this->report, 'TOC-A1');

    $this->stranger = $mkUser('toc-stranger@example.com');
    $mkEmployee($this->stranger, 'TOC-A2');

    // The dual role: supervises TOC-A1 and is an employee (TOC-S1) himself.
    $this->sup = $mkUser('toc-sup@example.com', 'Supervisor');
    $mkEmployee($this->sup, 'TOC-S1');
    DB::table('employee_supervisors')->insert([
        'employee_id' => 'TOC-A1',
        'supervisor_id' => $this->sup->id,
        'supervisor_type' => 'Supervisor',
    ]);

    $this->hr = $mkUser('toc-hr@example.com', 'HR Admin');
    $this->outsider = $mkUser('toc-outsider@example.com');
});

/** File an on-behalf claim as a given user. */
function tocClaim(object $t, object $as, string $code, array $overrides = []): mixed
{
    return $t->actingAs($as, 'sanctum')->postJson('/api/v1/team/overtimes/claim', array_merge([
        'employee_id' => $code,
        'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime',
        'hours' => 2,
        'reason' => 'Month-end closing',
    ], $overrides));
}

test('a supervisor claims overtime for a report', function (): void {
    tocClaim($this, $this->sup, 'TOC-A1')->assertCreated();

    $row = DB::table('roster_overtimes')->where('employee_id', 'TOC-A1')->firstOrFail();
    expect($row->status)->toBe('Pending')->and((float) $row->hours)->toBe(2.0);

    // The first stage's desk was pinged, exactly like a self-claim.
    expect(DB::table('notifications')->where('notifiable_id', $this->sup->id)->count())->toBeGreaterThan(0);
});

test('claims outside scope answer 403', function (): void {
    tocClaim($this, $this->sup, 'TOC-A2')->assertStatus(403);
    tocClaim($this, $this->outsider, 'TOC-A1')->assertStatus(403);

    expect(DB::table('roster_overtimes')->count())->toBe(0);
});

test('HR claims for anyone', function (): void {
    tocClaim($this, $this->hr, 'TOC-A2')->assertCreated();

    expect(DB::table('roster_overtimes')->where('employee_id', 'TOC-A2')->count())->toBe(1);
});

test('extra duty and holiday duty claim through the same endpoint', function (): void {
    tocClaim($this, $this->sup, 'TOC-A1', ['overtime_type' => 'Extra Duty'])->assertCreated();
    tocClaim($this, $this->sup, 'TOC-A1', ['overtime_type' => 'Holiday Duty'])->assertCreated();

    expect(DB::table('roster_overtimes')->where('employee_id', 'TOC-A1')->count())->toBe(2);
});

test('a duplicate date and type is refused', function (): void {
    tocClaim($this, $this->sup, 'TOC-A1')->assertCreated();
    tocClaim($this, $this->sup, 'TOC-A1')->assertStatus(422);
});

test('hours are computed from start and end time', function (): void {
    tocClaim($this, $this->sup, 'TOC-A1', ['hours' => null, 'start_time' => '18:00', 'end_time' => '20:30'])
        ->assertCreated();

    expect((float) DB::table('roster_overtimes')->where('employee_id', 'TOC-A1')->value('hours'))->toBe(2.5);
});

test('a supervisor who is also an employee files for self via self-service', function (): void {
    // Self: the login resolves to their own code.
    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/overtimes', [
        'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime',
        'hours' => 1.5,
        'reason' => 'My own late sitting',
    ])->assertCreated()->assertJsonPath('data.employee_id', 'TOC-S1');

    // The team endpoint is for reports: their own code is outside their scope.
    tocClaim($this, $this->sup, 'TOC-S1')->assertStatus(403);
});

<?php

/**
 * Phase-3 guards: team scope, roster writes, tasks, notices, org, CEO cockpit.
 *
 * The one rule everything pins: a supervisor only ever touches their own
 * reports (403 outside scope, never a leak), while HR/DGM/GM/CEO/Admins see
 * the whole organisation. CEO endpoints additionally need the CEO role.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;
use Modules\HRM\Database\Seeders\TeamWorkNoticeSeeder;

beforeEach(function (): void {
    $this->seed(ApprovalChainSeeder::class);
    $this->seed(TeamWorkNoticeSeeder::class);

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

    $this->a1 = $mkUser('a1@example.com');
    $mkEmployee($this->a1, 'EMP-A1');
    $this->a2 = $mkUser('a2@example.com');
    $mkEmployee($this->a2, 'EMP-A2');

    $this->sup = $mkUser('sup@example.com', 'Supervisor');
    $mkEmployee($this->sup, 'EMP-S1');
    DB::table('employee_supervisors')->insert([
        'employee_id' => 'EMP-A1',
        'supervisor_id' => $this->sup->id,
        'supervisor_type' => 'Supervisor',
    ]);

    $this->hr = $mkUser('hr@example.com', 'HR Admin');
    $this->gm = $mkUser('gm@example.com', 'General Manager');
    $this->ceo = $mkUser('ceo@example.com', 'CEO');
    $this->outsider = $mkUser('outsider@example.com');

    Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $this->admin = $mkUser('admin@example.com', 'Super Admin');

    $this->leaveTypeId = DB::table('leave_types')->insertGetId([
        'name' => 'Annual', 'code' => 'AN', 'days_per_year' => 14,
        'is_paid' => true, 'is_active' => true,
    ]);
});

function ph3Apply(object $t, ?int $typeId = null, int $offset = 7): int
{
    $res = $t->actingAs($t->a1, 'sanctum')->postJson('/api/v1/leaves', [
        'leave_type_id' => $typeId ?? $t->leaveTypeId,
        'from_date' => now()->addDays($offset)->toDateString(),
        'to_date' => now()->addDays($offset + 1)->toDateString(),
        'reason' => 'Family trip',
    ]);
    $res->assertCreated();

    return $res->json('data.id');
}

// ------------------------------------------------------------------ team ---

test('team members resolve by scope', function (): void {
    $sup = $this->actingAs($this->sup, 'sanctum')->getJson('/api/v1/team/members');
    $sup->assertOk();
    expect(collect($sup->json('data'))->pluck('employee_id')->all())->toBe(['EMP-A1']);

    $gm = $this->actingAs($this->gm, 'sanctum')->getJson('/api/v1/team/members');
    expect($gm->json('meta.total'))->toBe(3);

    $this->actingAs($this->outsider, 'sanctum')
        ->getJson('/api/v1/team/members')
        ->assertOk()->assertJsonPath('meta.total', 0);
});

test('team attendance is scope-checked per report', function (): void {
    $today = now()->toDateString();
    DB::table('attendance_logs')->insert([
        'employee_id' => 'EMP-A1', 'punch_time' => "{$today} 09:01:00", 'punch_type' => 'IN',
    ]);

    $range = 'employee_id=EMP-A1&from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString();

    $this->actingAs($this->sup, 'sanctum')
        ->getJson("/api/v1/team/attendance/logs?{$range}")
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson("/api/v1/team/attendance/logs?employee_id=EMP-A2&from={$today}&to={$today}")
        ->assertStatus(403);

    $this->actingAs($this->gm, 'sanctum')
        ->getJson("/api/v1/team/attendance/summary?employee_id=EMP-A2")
        ->assertOk()->assertJsonPath('data.employee_id', 'EMP-A2');
});

test('team leave list and on-behalf filing stay in scope', function (): void {
    ph3Apply($this);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/team/leaves')
        ->assertOk()->assertJsonPath('meta.total', 1);

    // On-behalf: supervisor files FOR the report (chain snapshot included).
    $res = $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/team/leaves/apply', [
        'employee_id' => 'EMP-A1',
        'leave_type_id' => $this->leaveTypeId,
        'from_date' => now()->addDays(20)->toDateString(),
        'to_date' => now()->addDays(21)->toDateString(),
        'reason' => 'Filed by supervisor',
    ]);
    $res->assertCreated();
    $steps = json_decode(DB::table('leave_request_workflows')->where('leave_request_id', $res->json('data.id'))->value('steps'), true);
    expect($steps)->toHaveCount(5);

    // Outside the team: no list, no filing.
    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/team/leaves?employee_id=EMP-A2')
        ->assertStatus(403);

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/team/leaves/apply', [
        'employee_id' => 'EMP-A2',
        'leave_type_id' => $this->leaveTypeId,
        'from_date' => now()->addDays(20)->toDateString(),
        'to_date' => now()->addDays(21)->toDateString(),
    ])->assertStatus(403);
});

test('team overtime and roster lists stay in scope', function (): void {
    DB::table('roster_overtimes')->insert([
        'employee_id' => 'EMP-A1', 'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime', 'hours' => 2, 'status' => 'Pending',
    ]);
    DB::table('roster_duties')->insert([
        'employee_id' => 'EMP-A1', 'roster_date' => now()->toDateString(), 'status' => 'Scheduled',
    ]);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/team/overtimes')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/team/roster')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/team/overtimes?employee_id=EMP-A2')
        ->assertStatus(403);
});

// ---------------------------------------------------------------- roster ---

test('roster bulk set validates scope and the 60-day cap', function (): void {
    $shiftId = DB::table('roster_shifts')->insertGetId([
        'shift_name' => 'Morning', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    $from = now()->addDays(1)->toDateString();
    $to = now()->addDays(3)->toDateString();

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/roster/duties/bulk', [
        'employee_ids' => ['EMP-A1'],
        'shift_id' => $shiftId,
        'from_date' => $from,
        'to_date' => $to,
    ])->assertCreated()->assertJsonPath('data.assigned', 3);

    expect(DB::table('roster_duties')->where('employee_id', 'EMP-A1')->count())->toBe(3);

    // One outsider in the batch rejects the whole batch before anything writes.
    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/roster/duties/bulk', [
        'employee_ids' => ['EMP-A1', 'EMP-A2'],
        'shift_id' => $shiftId,
        'from_date' => now()->addDays(10)->toDateString(),
        'to_date' => now()->addDays(11)->toDateString(),
    ])->assertStatus(403);

    expect(DB::table('roster_duties')->where('roster_date', now()->addDays(10)->toDateString())->count())->toBe(0);

    // 61 days breaches the cap.
    $this->actingAs($this->gm, 'sanctum')->postJson('/api/v1/roster/duties/bulk', [
        'employee_ids' => ['EMP-A1'],
        'shift_id' => $shiftId,
        'from_date' => $from,
        'to_date' => now()->addDays(62)->toDateString(),
    ])->assertStatus(422);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/roster/shifts')
        ->assertOk()->assertJsonPath('data.0.shift_name', 'Morning');
});

test('roster single set and remove are scope-checked', function (): void {
    $shiftId = DB::table('roster_shifts')->insertGetId(['shift_name' => 'Night']);

    $created = $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/roster/duties', [
        'employee_id' => 'EMP-A1',
        'roster_date' => now()->addDays(5)->toDateString(),
        'shift_id' => $shiftId,
    ]);
    $created->assertCreated();
    $id = $created->json('data.id');

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/roster/duties', [
        'employee_id' => 'EMP-A2',
        'roster_date' => now()->addDays(5)->toDateString(),
    ])->assertStatus(403);

    // A2's own duty is untouchable by A1's supervisor, removable by GM.
    $otherId = DB::table('roster_duties')->insertGetId([
        'employee_id' => 'EMP-A2', 'roster_date' => now()->toDateString(), 'status' => 'Scheduled',
    ]);
    $this->actingAs($this->sup, 'sanctum')->deleteJson("/api/v1/roster/duties/{$otherId}")->assertStatus(403);
    $this->actingAs($this->gm, 'sanctum')->deleteJson("/api/v1/roster/duties/{$otherId}")->assertOk();

    $this->actingAs($this->sup, 'sanctum')->deleteJson("/api/v1/roster/duties/{$id}")->assertOk();
});

// ----------------------------------------------------------------- tasks ---

test('task assign needs the permission and the scope', function (): void {
    $res = $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A1',
        'title' => 'Count the Ulao warehouse',
        'priority' => 'High',
        'due_date' => now()->addDays(3)->toDateString(),
    ]);
    $res->assertCreated()->assertJsonPath('data.status', 'Open');

    // The assignee was pinged.
    expect(DB::table('notifications')->where('notifiable_id', $this->a1->id)->where('data', 'like', '%task%')->count())
        ->toBeGreaterThan(0);

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A2',
        'title' => 'Not my report',
    ])->assertStatus(403);

    $this->actingAs($this->outsider, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A1',
        'title' => 'No permission',
    ])->assertStatus(403);
});

test('task progress belongs to the assignee and follows the lanes', function (): void {
    $id = $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A1',
        'title' => 'Recount aisle 4',
    ])->json('data.id');

    $this->actingAs($this->a1, 'sanctum')
        ->patchJson("/api/v1/tasks/{$id}/progress", ['status' => 'In Progress'])
        ->assertOk()->assertJsonPath('data.status', 'In Progress');

    // The supervisor cannot walk the assignee's lanes.
    $this->actingAs($this->sup, 'sanctum')
        ->patchJson("/api/v1/tasks/{$id}/progress", ['status' => 'Done'])
        ->assertStatus(403);

    $this->actingAs($this->a1, 'sanctum')
        ->patchJson("/api/v1/tasks/{$id}/progress", ['status' => 'Done', 'remarks' => 'Counted twice.'])
        ->assertOk();

    // Done is terminal for the assignee.
    $this->actingAs($this->a1, 'sanctum')
        ->patchJson("/api/v1/tasks/{$id}/progress", ['status' => 'Open'])
        ->assertStatus(422);

    // The assigner was told it landed.
    expect(DB::table('notifications')->where('notifiable_id', $this->sup->id)->count())->toBeGreaterThan(0);
});

test('task verify, close and reopen belong to the assigner', function (): void {
    $id = $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A1',
        'title' => 'Verify me',
    ])->json('data.id');

    // Nothing to verify yet.
    $this->actingAs($this->sup, 'sanctum')
        ->postJson("/api/v1/tasks/{$id}/verify")
        ->assertStatus(422);

    $this->actingAs($this->a1, 'sanctum')
        ->patchJson("/api/v1/tasks/{$id}/progress", ['status' => 'Done'])->assertOk();

    // GM (all-scope, not the assigner) may still decide.
    $this->actingAs($this->gm, 'sanctum')
        ->postJson("/api/v1/tasks/{$id}/verify")
        ->assertOk()->assertJsonPath('data.status', 'Verified');

    $this->actingAs($this->sup, 'sanctum')
        ->postJson("/api/v1/tasks/{$id}/close")
        ->assertOk()->assertJsonPath('data.status', 'Closed');

    $this->actingAs($this->sup, 'sanctum')
        ->postJson("/api/v1/tasks/{$id}/reopen")
        ->assertOk()->assertJsonPath('data.status', 'Open');

    $this->actingAs($this->outsider, 'sanctum')
        ->postJson("/api/v1/tasks/{$id}/close")
        ->assertStatus(403);
});

test('task lists split mine, assigned and team', function (): void {
    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A1', 'title' => 'T1',
    ])->assertCreated();
    $this->actingAs($this->gm, 'sanctum')->postJson('/api/v1/tasks', [
        'employee_id' => 'EMP-A2', 'title' => 'T2',
    ])->assertCreated();

    $this->actingAs($this->a1, 'sanctum')
        ->getJson('/api/v1/tasks/mine')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/tasks/assigned')
        ->assertOk()->assertJsonPath('meta.total', 1);

    // Supervisor's team register holds T1 only; GM's holds both.
    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/tasks/team')
        ->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($this->gm, 'sanctum')
        ->getJson('/api/v1/tasks/team')
        ->assertOk()->assertJsonPath('meta.total', 2);
});

// --------------------------------------------------------------- notices ---

test('notice posting is permission- and scope-gated', function (): void {
    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Gate drill',
        'body' => 'Evacuation drill at 3pm for my team.',
        'audience_type' => 'employees',
        'audience_ids' => ['EMP-A1'],
    ])->assertCreated();

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'All hands',
        'body' => 'Supervisors cannot address everyone.',
        'audience_type' => 'all',
    ])->assertStatus(403);

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Stranger',
        'body' => 'Outside my team.',
        'audience_type' => 'employees',
        'audience_ids' => ['EMP-A2'],
    ])->assertStatus(403);

    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Ghost',
        'body' => 'Unknown code.',
        'audience_type' => 'employees',
        'audience_ids' => ['EMP-NOPE'],
    ])->assertStatus(422);

    $this->actingAs($this->hr, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Holiday',
        'body' => 'Office closed Friday.',
        'audience_type' => 'all',
    ])->assertCreated();

    $this->actingAs($this->outsider, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Nope',
        'body' => 'No permission.',
        'audience_type' => 'all',
    ])->assertStatus(403);
});

test('notice visibility, expiry and retract', function (): void {
    $this->actingAs($this->sup, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'For A1',
        'body' => 'Only A1 reads this.',
        'audience_type' => 'employees',
        'audience_ids' => ['EMP-A1'],
    ])->assertCreated();

    $gone = $this->actingAs($this->hr, 'sanctum')->postJson('/api/v1/notices', [
        'title' => 'Expired',
        'body' => 'Nobody reads this.',
        'audience_type' => 'all',
        'expire_at' => now()->addHour()->toDateTimeString(),
    ])->json('data.id');
    DB::table('hrm_notices')->where('id', $gone)->update(['expire_at' => now()->subHour()->toDateTimeString()]);

    $this->actingAs($this->a1, 'sanctum')
        ->getJson('/api/v1/notices/mine')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'For A1');

    $this->actingAs($this->a2, 'sanctum')
        ->getJson('/api/v1/notices/mine')
        ->assertOk()->assertJsonPath('meta.total', 0);

    $id = DB::table('hrm_notices')->where('title', 'For A1')->value('id');
    $this->actingAs($this->a1, 'sanctum')
        ->postJson("/api/v1/notices/{$id}/retract")
        ->assertStatus(403);

    $this->actingAs($this->sup, 'sanctum')
        ->postJson("/api/v1/notices/{$id}/retract")
        ->assertOk();

    $this->actingAs($this->a1, 'sanctum')
        ->getJson('/api/v1/notices/mine')
        ->assertOk()->assertJsonPath('meta.total', 0);
});

// ------------------------------------------------------------------- org ---

test('organisation directory is all-scope only', function (): void {
    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/org/employees')
        ->assertStatus(403);

    $this->actingAs($this->gm, 'sanctum')
        ->getJson('/api/v1/org/employees?search=EMP-A1')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_id', 'EMP-A1');

    $this->actingAs($this->sup, 'sanctum')
        ->getJson('/api/v1/org/departments')
        ->assertOk();
});

// ------------------------------------------------------------------- ceo ---

test('ceo cockpit is gated to CEO and Super Admin', function (): void {
    $this->actingAs($this->gm, 'sanctum')
        ->getJson('/api/v1/ceo/dashboard')
        ->assertStatus(403);

    $this->actingAs($this->outsider, 'sanctum')
        ->getJson('/api/v1/ceo/statistics')
        ->assertStatus(403);

    $this->actingAs($this->ceo, 'sanctum')
        ->getJson('/api/v1/ceo/dashboard')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'headcount', 'today', 'on_leave_today', 'approvals', 'overtime_month_hours', 'payroll', 'provident_fund',
        ]]);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/ceo/report')
        ->assertOk();
});

test('ceo dashboard numbers read the live state', function (): void {
    DB::table('attendance_dailies')->insert([
        'employee_id' => 'EMP-A1', 'duty_date' => now()->toDateString(), 'status' => 'Present',
        'primary_worked_hours' => 8, 'total_overtime_hours' => 0, 'grand_total_hours' => 8,
    ]);
    DB::table('roster_overtimes')->insert([
        'employee_id' => 'EMP-A1', 'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime', 'hours' => 2, 'status' => 'Approved',
    ]);
    ph3Apply($this);

    $res = $this->actingAs($this->ceo, 'sanctum')->getJson('/api/v1/ceo/dashboard');
    $res->assertOk()
        ->assertJsonPath('data.headcount.active', 3)
        ->assertJsonPath('data.today.present', 1)
        ->assertJsonPath('data.approvals.leaves_pending', 1)
        ->assertJsonPath('data.overtime_month_hours', 2);
});

test('ceo statistics and report answer with shaped series', function (): void {
    $this->actingAs($this->ceo, 'sanctum')
        ->getJson('/api/v1/ceo/statistics?months=3')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['month', 'joined', 'exits', 'attendance_rate', 'leave_days', 'overtime_hours']]]);

    $this->actingAs($this->ceo, 'sanctum')
        ->getJson('/api/v1/ceo/report?month='.now()->format('Y-m'))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'attendance', 'leave_by_type', 'overtime_by_department', 'approvals_aging', 'movement',
        ]]);
});

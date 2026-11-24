<?php

/**
 * Mobile API v1 guards: auth + employee self-service.
 *
 * Every test pins one of two contracts:
 *
 *  1. SCOPING — the employee always comes from the token user. A request for a
 *     colleague's leave/payslip/OT answers 404 (never 200, never a leak), and
 *     a login with no linked employee answers 409 EMPLOYEE_NOT_LINKED.
 *  2. REUSE — writes travel through the same services the web desks use, so
 *     balance guards, duplicate guards and workflow snapshots behave alike.
 *
 * Fixtures are written with the query builder (not factories) so the tests pin
 * real column contracts and never depend on factory defaults.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\CORE\Models\User;

function apiUser(string $email = 'emp.t01@example.com'): User
{
    return User::create([
        'name' => 'API Employee',
        'email' => $email,
        'password' => Hash::make('secret123'),
        'email_verified_at' => now(),
    ]);
}

function apiEmployee(User $user, string $code = 'EMP-T01'): void
{
    DB::table('employees')->insert([
        'employee_id' => $code,
        'user_id' => $user->id,
        'first_name' => 'API',
        'last_name' => 'Employee',
        'status' => 'Active',
        'joining_date' => '2024-06-01',
        'is_active' => true,
    ]);
}

function apiLeaveType(string $name = 'Annual', int $days = 14): int
{
    return DB::table('leave_types')->insertGetId([
        'name' => $name,
        'code' => strtoupper(substr($name, 0, 2)),
        'days_per_year' => $days,
        'is_paid' => true,
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------- auth ---

test('login returns a bearer token and the profile payload', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $res = $this->postJson('/api/v1/auth/login', [
        'email' => 'emp.t01@example.com',
        'password' => 'secret123',
        'device_name' => 'pest',
    ]);

    $res->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'emp.t01@example.com')
        ->assertJsonPath('data.user.employee.employee_id', 'EMP-T01');

    expect($res->json('data.token'))->toBeString()->not->toBe('');

    // The token really authenticates.
    $me = $this->withToken($res->json('data.token'))->getJson('/api/v1/auth/me');
    $me->assertOk()->assertJsonPath('data.employee.employee_id', 'EMP-T01');
});

test('login rejects a wrong password with 401', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'emp.t01@example.com',
        'password' => 'nope',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

test('guest requests answer 401', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->getJson('/api/v1/dashboard')->assertStatus(401);
});

test('logout revokes the calling token', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'emp.t01@example.com',
        'password' => 'secret123',
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    // The token string itself no longer resolves (same lookup Sanctum runs per request).
    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($token))->toBeNull();
});

test('a login with no linked employee answers 409, not a leak', function (): void {
    $user = apiUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertStatus(409)
        ->assertJsonPath('errors.code', 'EMPLOYEE_NOT_LINKED');
});

// ------------------------------------------------------------ dashboard ---

test('dashboard mirrors the web self-service home', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.employee.employee_id', 'EMP-T01')
        ->assertJsonStructure(['data' => [
            'employee', 'slips', 'leave', 'attendance', 'pending',
        ]]);
});

// ---------------------------------------------------------------- leave ---

test('leave types, balances and estimate answer', function (): void {
    $user = apiUser();
    apiEmployee($user);
    $typeId = apiLeaveType();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/leaves/types')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Annual');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/leaves/balances')
        ->assertOk()
        ->assertJsonPath('success', true);

    $from = now()->addDays(7)->toDateString();
    $to = now()->addDays(8)->toDateString();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/leaves/estimate?leave_type_id={$typeId}&from_date={$from}&to_date={$to}")
        ->assertOk()
        ->assertJsonPath('data.days', 2)
        ->assertJsonPath('data.sufficient', true);
});

test('leave apply, track and withdraw flow through the web service', function (): void {
    $user = apiUser();
    apiEmployee($user);
    $typeId = apiLeaveType();

    $from = now()->addDays(7)->toDateString();
    $to = now()->addDays(8)->toDateString();

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leaves', [
        'leave_type_id' => $typeId,
        'from_date' => $from,
        'to_date' => $to,
        'reason' => 'Family trip',
    ]);
    $created->assertCreated()->assertJsonPath('success', true);
    $id = $created->json('data.id');
    expect($id)->not->toBeNull();

    // Tracking list shows it.
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/leaves?status=Pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // Tracking detail carries the workflow snapshot + current stage.
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/leaves/{$id}")
        ->assertOk()
        ->assertJsonPath('data.current_stage', 'HR')
        ->assertJsonStructure(['data' => ['detail', 'history', 'current_stage']]);

    // Withdraw works while Pending.
    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/leaves/{$id}")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/leaves/{$id}")
        ->assertStatus(404);
});

test('leave apply enforces the balance guard', function (): void {
    $user = apiUser();
    apiEmployee($user);
    $typeId = apiLeaveType('Annual', 14);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/leaves', [
        'leave_type_id' => $typeId,
        'from_date' => now()->addDays(7)->toDateString(),
        'to_date' => now()->addDays(40)->toDateString(),
    ])->assertStatus(422)->assertJsonPath('success', false);
});

test('one employee cannot read or withdraw another leave', function (): void {
    $me = apiUser('me@example.com');
    apiEmployee($me, 'EMP-ME');
    $other = apiUser('other@example.com');
    apiEmployee($other, 'EMP-OT');
    $typeId = apiLeaveType();

    $otherLeaveId = DB::table('leave_requests')->insertGetId([
        'employee_id' => 'EMP-OT',
        'leave_type_id' => $typeId,
        'from_date' => now()->addDays(7)->toDateString(),
        'to_date' => now()->addDays(8)->toDateString(),
        'days' => 2,
        'status' => 'Pending',
    ]);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/leaves/{$otherLeaveId}")
        ->assertStatus(404);

    $this->actingAs($me, 'sanctum')
        ->deleteJson("/api/v1/leaves/{$otherLeaveId}")
        ->assertStatus(404);

    expect(DB::table('leave_requests')->where('id', $otherLeaveId)->exists())->toBeTrue();
});

test('withdrawing a decided leave answers 422', function (): void {
    $user = apiUser();
    apiEmployee($user);
    $typeId = apiLeaveType();

    $id = DB::table('leave_requests')->insertGetId([
        'employee_id' => 'EMP-T01',
        'leave_type_id' => $typeId,
        'from_date' => now()->addDays(7)->toDateString(),
        'to_date' => now()->addDays(8)->toDateString(),
        'days' => 2,
        'status' => 'Approved',
    ]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/leaves/{$id}")
        ->assertStatus(422);
});

// ------------------------------------------------------------- overtime ---

test('overtime claim, duplicate guard and cancel', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $payload = [
        'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Extra Duty',
        'start_time' => '18:00',
        'end_time' => '21:00',
        'reason' => 'Stock count',
    ];

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtimes', $payload);
    $created->assertCreated();
    $id = $created->json('data.id');
    expect((float) $created->json('data.hours'))->toBe(3.0);

    // Same date + type again → duplicate guard.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/overtimes', $payload)
        ->assertStatus(422);

    // Type filter finds it.
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/overtimes?overtime_type=Extra Duty')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/overtimes/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'Cancelled');
});

test('overtime rejects future dates and a colleague record stays invisible', function (): void {
    $me = apiUser('me@example.com');
    apiEmployee($me, 'EMP-ME');
    $other = apiUser('other@example.com');
    apiEmployee($other, 'EMP-OT');

    $this->actingAs($me, 'sanctum')->postJson('/api/v1/overtimes', [
        'overtime_date' => now()->addDay()->toDateString(),
        'overtime_type' => 'Overtime',
        'hours' => 2,
    ])->assertStatus(422);

    $otherOtId = DB::table('roster_overtimes')->insertGetId([
        'employee_id' => 'EMP-OT',
        'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime',
        'hours' => 2,
        'status' => 'Pending',
    ]);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/overtimes/{$otherOtId}")
        ->assertStatus(404);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/overtimes')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// ----------------------------------------------------------- attendance ---

test('attendance log, pairs, daily and summary scope to self', function (): void {
    $me = apiUser('me@example.com');
    apiEmployee($me, 'EMP-ME');
    $other = apiUser('other@example.com');
    apiEmployee($other, 'EMP-OT');

    $today = now()->toDateString();
    DB::table('attendance_logs')->insert([
        ['employee_id' => 'EMP-ME', 'punch_time' => "{$today} 09:02:00", 'punch_type' => 'IN'],
        ['employee_id' => 'EMP-ME', 'punch_time' => "{$today} 18:05:00", 'punch_type' => 'OUT'],
        ['employee_id' => 'EMP-OT', 'punch_time' => "{$today} 09:00:00", 'punch_type' => 'IN'],
    ]);
    DB::table('attendance_pairs')->insert([
        'employee_id' => 'EMP-ME', 'duty_date' => $today,
        'punch_in' => "{$today} 09:02:00", 'punch_out' => "{$today} 18:05:00",
        'worked_hours' => 9.05,
    ]);
    DB::table('attendance_dailies')->insert([
        ['employee_id' => 'EMP-ME', 'duty_date' => $today, 'status' => 'Present', 'primary_worked_hours' => 8, 'total_overtime_hours' => 1, 'grand_total_hours' => 9],
        ['employee_id' => 'EMP-ME', 'duty_date' => now()->subDay()->toDateString(), 'status' => 'Late', 'primary_worked_hours' => 8, 'total_overtime_hours' => 0, 'grand_total_hours' => 8],
        ['employee_id' => 'EMP-OT', 'duty_date' => $today, 'status' => 'Absent', 'primary_worked_hours' => 0, 'total_overtime_hours' => 0, 'grand_total_hours' => 0],
    ]);

    $range = "from=".now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString();

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/attendance/logs?{$range}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/attendance/pairs?{$range}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/attendance/daily?{$range}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/attendance/summary?month='.now()->format('Y-m'))
        ->assertOk()
        ->assertJsonPath('data.present', 2)
        ->assertJsonPath('data.late', 1)
        ->assertJsonPath('data.total_hours', 17);
});

// ------------------------------------------------------- regularization ---

test('regularization apply, duplicate guard and cancel', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $payload = [
        'duty_date' => now()->toDateString(),
        'regularization_type' => 'Missing Out',
        'requested_out_time' => '18:00',
        'reason' => 'Forgot to punch out at the gate.',
    ];

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/regularizations', $payload);
    $created->assertCreated();
    $id = $created->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/regularizations', $payload)
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/regularizations/types')
        ->assertOk()
        ->assertJsonFragment(['Missing Out']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/regularizations/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'Cancelled');
});

// ------------------------------------------------------------- informed ---

test('informed request apply and list', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/informed/types')
        ->assertOk()
        ->assertJsonFragment(['Field Work']);

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/informed', [
        'informed_date' => now()->toDateString(),
        'informed_type' => 'Field Work',
        'reason' => 'Client visit at Uttara.',
    ]);
    $created->assertCreated()->assertJsonPath('data.status', 'Pending');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/informed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

// ---------------------------------------------------------- resignation ---

test('resignation apply and one-pending guard', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $payload = [
        'last_working_date' => now()->addDays(30)->toDateString(),
        'reason' => 'Pursuing higher studies abroad.',
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/resignations', $payload)
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/resignations', $payload)
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/resignations')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'Pending');
});

// ---------------------------------------------------------- certificates ---

test('certificate request, duplicate guard and cancel', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/certificates/types')
        ->assertOk()
        ->assertJsonFragment(['NOC Certificate']);

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/certificates', [
        'certificate_type' => 'Salary Certificate',
        'purpose' => 'Visa application for Thailand trip.',
    ]);
    $created->assertCreated();
    $id = $created->json('data.id');

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/certificates', [
        'certificate_type' => 'Salary Certificate',
        'purpose' => 'Second request while one is pending.',
    ])->assertStatus(422);

    // A different certificate while one pends is fine.
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/certificates', [
        'certificate_type' => 'Experience Certificate',
        'purpose' => 'Background check for a new role.',
    ])->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/certificates/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'Cancelled');
});

// -------------------------------------------------------------- payslip ---

test('payslips list and show scope to self', function (): void {
    $me = apiUser('me@example.com');
    apiEmployee($me, 'EMP-ME');
    $other = apiUser('other@example.com');
    apiEmployee($other, 'EMP-OT');

    $sheetId = DB::table('payroll_salary_sheets')->insertGetId([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'released',
    ]);
    $mySlip = DB::table('payroll_slips')->insertGetId([
        'salary_sheet_id' => $sheetId, 'employee_id' => 'EMP-ME', 'employee_name' => 'API Employee',
        'pay_year' => 2026, 'pay_month' => 10,
        'basic_salary' => 50000, 'gross_salary' => 80000,
        'total_deductions' => 5000, 'net_salary' => 75000,
        'payment_status' => 'paid', 'breakdown_json' => '{}',
    ]);
    $otherSlip = DB::table('payroll_slips')->insertGetId([
        'salary_sheet_id' => $sheetId, 'employee_id' => 'EMP-OT', 'employee_name' => 'Other',
        'pay_year' => 2026, 'pay_month' => 10, 'breakdown_json' => '{}',
    ]);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/payslips')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/payslips/{$mySlip}")
        ->assertOk()
        ->assertJsonPath('data.net_salary', '75000.00');

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/payslips/{$otherSlip}")
        ->assertStatus(404);
});

// ----------------------------------------------------------------- loan ---

test('loan apply succeeds with a salary record', function (): void {
    $user = apiUser();
    apiEmployee($user);
    DB::table('employee_salaries')->insert([
        'employee_id' => 'EMP-T01',
        'basic_salary' => 50000,
        'gross_salary' => 80000,
        'effective_from' => '2024-01-01',
        'is_current' => true,
    ]);

    $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/loans', [
        'principal_amount' => 60000,
        'installment_count' => 6,
        'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'purpose' => 'Medical treatment for my father.',
    ]);
    $created->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.installment_amount', 10000);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/loans')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('loan apply fails without a salary record and on bad input', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/loans', [
        'principal_amount' => 60000,
        'installment_count' => 6,
        'first_due_date' => now()->addMonth()->toDateString(),
        'purpose' => 'Medical treatment for my father.',
    ])->assertStatus(422);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/loans', [
        'principal_amount' => -5,
        'installment_count' => 200,
        'first_due_date' => 'not-a-date',
        'purpose' => 'x',
    ])->assertStatus(422);
});

// ------------------------------------------------------------------- pf ---

test('provident fund account and ledger answer, 404 without an account', function (): void {
    $me = apiUser('me@example.com');
    apiEmployee($me, 'EMP-ME');
    $bare = apiUser('bare@example.com');
    apiEmployee($bare, 'EMP-BARE');

    $accountId = DB::table('pf_accounts')->insertGetId([
        'employee_id' => 'EMP-ME',
        'employee_balance' => 10000,
        'employer_balance' => 10000,
        'status' => 'active',
    ]);
    DB::table('pf_ledgers')->insert([
        'pf_account_id' => $accountId, 'entry_type' => 'employee_contribution',
        'amount' => 5000, 'entry_date' => now()->subMonth()->toDateString(),
    ]);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/pf/account')
        ->assertOk()
        ->assertJsonPath('data.total_balance', 20000);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/pf/ledger')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($me, 'sanctum')
        ->getJson('/api/v1/pf/withdrawals')
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->actingAs($bare, 'sanctum')
        ->getJson('/api/v1/pf/account')
        ->assertStatus(404);
});

// -------------------------------------------------------- notifications ---

test('notification bell: list, unread count, read', function (): void {
    $user = apiUser();
    apiEmployee($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread', 0);

    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\TestNotice',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'Hello']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread', 1);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread', 0);
});

<?php

use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
use Modules\HRM\Services\LeaveBalanceService;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveWorkspaceService;
use Modules\HRM\Services\LeaveYearEndService;

/**
 * Leave, audited.
 *
 * Every test here pins a defect that was live in the module this replaces, and each one is written as
 * the behaviour a human would check rather than as a unit test of a formula:
 *
 *   - carry-forward was computed by the year-end and read by nothing, so carrying leave forward
 *     carried nothing (B1);
 *   - every employee was granted the full year's entitlement regardless of when they joined, on a
 *     register where 219 people joined during the year (B3);
 *   - a closed year never read as closed, because `is_closed` was written as `false` (B4);
 *   - the year-end screen referenced a relationship the model did not declare, so the page 500'd (B5);
 *   - approving never re-checked the balance, and rejecting required no reason (G2);
 *   - cancelling an approved leave left the attendance calendar marked "On Leave" for ever (G5);
 *   - the day-count policy was unreadable and un-editable from the screens that depended on it (G6);
 *   - the balance register added maternity leave to every employee's spendable days (the 227-day bug).
 */

/** An employee row with the columns the HRM model needs. */
function lvEmployee(string $id, array $overrides = []): Employee
{
    return Employee::query()->create(array_merge([
        'employee_id' => $id,
        'first_name' => "Emp {$id}",
        'full_name' => "Emp {$id}",
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => 1,
        'allow_overtime' => 1,
        'duty_hours' => 8,
    ], $overrides));
}

/** Salary is versioned in `employee_salaries`; the employee row itself carries none. */
function lvSalary(string $employeeId, float $basic = 30000, float $gross = 45000): void
{
    DB::table('employee_salaries')->insert([
        'employee_id' => $employeeId,
        'basic_salary' => $basic,
        'gross_salary' => $gross,
        'effective_from' => '2020-01-01',
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lvType(string $name, float $daysPerYear, array $overrides = []): LeaveType
{
    return LeaveType::query()->create(array_merge([
        'name' => $name,
        'code' => strtoupper(substr($name, 0, 2)),
        'days_per_year' => $daysPerYear,
        'is_paid' => true,
        'color' => '#0ea5e9',
        'is_active' => true,
    ], $overrides));
}

function lvPolicy(LeaveType $type, array $overrides = []): LeaveTypeSetting
{
    return LeaveTypeSetting::query()->updateOrCreate(['leave_type_id' => $type->id], array_merge([
        'day_count_mode' => 'calendar_days',
        'accrual_mode' => 'annual',
        'proration_mode' => 'none',
        'working_days' => [0, 1, 2, 3, 4],
        'approval_steps' => ['HR'],
        'is_carry_forward' => false,
    ], $overrides));
}

function lvRequest(string $employeeId, LeaveType $type, string $from, string $to, float $days, string $status = 'Pending'): LeaveRequest
{
    return LeaveRequest::query()->create([
        'employee_id' => $employeeId,
        'leave_type_id' => $type->id,
        'from_date' => $from,
        'to_date' => $to,
        'days' => $days,
        'is_half_day' => false,
        'reason' => 'Test',
        'status' => $status,
    ]);
}

beforeEach(function (): void {
    // Pest's in-memory SQLite has no seeded permissions; the module's routes are permission-gated.
    $user = User::factory()->create();
    $this->actingAs($user);
    \Illuminate\Support\Facades\Gate::before(fn () => true);
});

/* ------------------------------- B1: carry forward -------------------------------- */

it('adds the days a closed previous year carried into this year', function (): void {
    lvEmployee('E-1');
    $type = lvType('Earned Leave', 14);
    lvPolicy($type, ['is_carry_forward' => true, 'max_carry_days' => 5]);

    // Last year is closed and handed over 5 days.
    HrmLeaveBalance::query()->create([
        'employee_id' => 'E-1', 'leave_type_id' => $type->id, 'year' => 2025,
        'opening_balance' => 0, 'entitlement' => 14, 'carry_forward' => 5, 'availed' => 9,
        'pending' => 0, 'encashed' => 0, 'lapsed' => 0, 'available' => 5,
        'is_closed' => true, 'closed_at' => now(),
    ]);

    $balance = (new LeaveBalanceService)->balance('E-1', $type->id, 2026);

    // 14 this year + 5 carried = 19. The old formula returned 14 and the 5 days vanished.
    expect($balance['carry_forward'])->toBe(5.0)
        ->and($balance['total'])->toBe(19.0)
        ->and($balance['available'])->toBe(19.0);
});

it('does not carry anything out of a year that was never closed', function (): void {
    lvEmployee('E-2');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);
    lvRequest('E-2', $type, '2025-03-03', '2025-03-04', 2, 'Approved');

    $balance = (new LeaveBalanceService)->balance('E-2', $type->id, 2026);

    expect($balance['carry_forward'])->toBe(0.0)
        ->and($balance['available'])->toBe(10.0);
});

/* ------------------------- B3: entitlement for mid-year joiners -------------------- */

it('grants a mid-year joiner only the part of the year they served', function (): void {
    lvEmployee('E-3', ['joining_date' => '2026-10-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['proration_mode' => 'monthly']);

    // October, November, December = 3 of 12 months.
    expect((new LeaveBalanceService)->entitlement('E-3', $type, 2026))->toBe(3.0);
});

it('gives a full year to somebody who worked the whole year', function (): void {
    lvEmployee('E-4', ['joining_date' => '2019-06-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['proration_mode' => 'monthly']);

    expect((new LeaveBalanceService)->entitlement('E-4', $type, 2026))->toBe(12.0);
});

it('gives no entitlement in a year that starts before the employee joined', function (): void {
    lvEmployee('E-5', ['joining_date' => '2027-02-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['proration_mode' => 'monthly']);

    expect((new LeaveBalanceService)->entitlement('E-5', $type, 2026))->toBe(0.0);
});

it('pro-rates by days of service to the end of the year, not to today', function (): void {
    lvEmployee('E-6', ['joining_date' => '2026-01-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['proration_mode' => 'daily']);

    // The whole of 2026 was served, so the grant is the whole 12 — as of 31 December, whatever day
    // the report is run. The old code divided by 365 but used days elapsed, so this drifted all year.
    expect((new LeaveBalanceService)->entitlement('E-6', $type, 2026))->toBe(12.0);
});

/* --------------------------------- B4: closed is closed --------------------------- */

it('freezes a closed year so later applications cannot change it', function (): void {
    lvEmployee('E-7');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    HrmLeaveBalance::query()->create([
        'employee_id' => 'E-7', 'leave_type_id' => $type->id, 'year' => 2026,
        'opening_balance' => 0, 'entitlement' => 10, 'carry_forward' => 0, 'availed' => 4,
        'pending' => 0, 'encashed' => 0, 'lapsed' => 6, 'available' => 6,
        'is_closed' => true, 'closed_at' => now(),
    ]);

    // A back-dated approval lands after the close.
    lvRequest('E-7', $type, '2026-05-04', '2026-05-05', 2, 'Approved');

    $balance = (new LeaveBalanceService)->balance('E-7', $type->id, 2026);

    expect($balance['frozen'])->toBe(true)
        // The snapshot's 4 taken, not the live 6.
        ->and($balance['approved'])->toBe(4.0)
        ->and($balance['available'])->toBe(6.0);
});

it('closes a year by writing genuinely closed rows', function (): void {
    lvEmployee('E-8', ['joining_date' => '2020-01-01']);
    $type = lvType('Casual Leave', 10);
    lvPolicy($type, ['is_carry_forward' => true, 'max_carry_days' => 4]);

    $result = (new LeaveYearEndService(new LeaveBalanceService))->close(2026);

    $row = HrmLeaveBalance::query()->where('employee_id', 'E-8')->where('leave_type_id', $type->id)->where('year', 2026)->first();

    // Before this fix `is_closed` was written as false, so a closed year still read as open.
    expect($result['created'])->toBeGreaterThan(0)
        ->and($row)->not->toBeNull()
        ->and($row->is_closed)->toBeTrue()
        ->and((float) $row->carry_forward)->toBe(4.0)
        // 10 granted, 4 carried, 6 lapsed.
        ->and((float) $row->lapsed)->toBe(6.0);
});

it('hands the carry over as next year opening balance', function (): void {
    lvEmployee('E-9', ['joining_date' => '2020-01-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['is_carry_forward' => true, 'max_carry_days' => 5]);

    (new LeaveYearEndService(new LeaveBalanceService))->close(2026);

    $next = HrmLeaveBalance::query()->where('employee_id', 'E-9')->where('leave_type_id', $type->id)->where('year', 2027)->first();

    expect($next)->not->toBeNull()
        ->and((float) $next->opening_balance)->toBe(5.0);

    // And the new year's balance counts it: 12 entitlement + 5 opening.
    $balance = (new LeaveBalanceService)->balance('E-9', $type->id, 2027);
    expect($balance['total'])->toBe(17.0);
});

it('can reopen a closed year and clears the opening it seeded', function (): void {
    lvEmployee('E-10', ['joining_date' => '2020-01-01']);
    $type = lvType('Earned Leave', 12);
    lvPolicy($type, ['is_carry_forward' => true, 'max_carry_days' => 5]);
    $service = new LeaveYearEndService(new LeaveBalanceService);

    $service->close(2026);
    $reopened = $service->reopen(2026);

    $row = HrmLeaveBalance::query()->where('employee_id', 'E-10')->where('leave_type_id', $type->id)->where('year', 2026)->first();
    $next = HrmLeaveBalance::query()->where('employee_id', 'E-10')->where('leave_type_id', $type->id)->where('year', 2027)->first();

    expect($reopened['reopened'])->toBeGreaterThan(0)
        ->and($row->is_closed)->toBeFalse()
        ->and((float) ($next->opening_balance ?? 0))->toBe(0.0);
});

it('refuses to reopen a year that is not closed', function (): void {
    lvEmployee('E-11');
    lvType('Casual Leave', 10);

    expect(fn () => (new LeaveYearEndService(new LeaveBalanceService))->reopen(2026))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

/* -------------------------- G2: approval re-checks the balance -------------------- */

it('refuses an approval the balance can no longer cover', function (): void {
    lvEmployee('E-12');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    // Ten days already approved, so nothing is left — but this application was created before that.
    lvRequest('E-12', $type, '2026-02-02', '2026-02-11', 10, 'Approved');
    $pending = lvRequest('E-12', $type, '2026-06-01', '2026-06-03', 3, 'Pending');

    expect(fn () => (new LeaveRequestService(app(\Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface::class), new LeaveBalanceService))->approve($pending))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect($pending->fresh()->status)->toBe('Pending');
});

/* --------------------------- G5: attendance is reversible ------------------------- */

it('puts the attendance calendar back when an approved leave is deleted', function (): void {
    lvEmployee('E-13');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    DB::table('attendance_dailies')->insert([
        ['employee_id' => 'E-13', 'duty_date' => '2026-04-06', 'status' => 'Absent', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $leave = lvRequest('E-13', $type, '2026-04-06', '2026-04-07', 2, 'Pending');
    $service = app(LeaveRequestService::class);

    // Approving marks the day.
    $service->approve($leave);
    $marked = DB::table('attendance_dailies')->where('employee_id', 'E-13')->where('duty_date', '2026-04-06')->first();
    expect($marked->status)->toBe('On Leave');

    $approved = $leave->fresh();
    $service->delete($approved);

    // Before this fix nothing was ever un-marked: the employee stayed "On Leave" for ever.
    $restored = DB::table('attendance_dailies')->where('employee_id', 'E-13')->where('duty_date', '2026-04-06')->first();
    expect($restored->status)->toBe('Absent');
});

/* --------------------- G6 + the 227-day register: long leave is separate ----------- */

it('reports long leave apart from the ordinary days an employee can spend', function (): void {
    lvEmployee('E-14');
    $casual = lvType('Casual Leave', 10);
    $maternity = lvType('Maternity Leave', 180);
    lvPolicy($casual);
    lvPolicy($maternity);

    $register = (new LeaveBalanceService)->register(2026, 'E-14');
    $row = collect($register['rows'])->firstWhere('employee_id', 'E-14');

    expect($row['core_available'])->toBe(10.0)
        ->and($row['long_leave_available'])->toBe(180.0)
        ->and($row['available'])->toBe(190.0)
        // And the type list says which is which, so the screen cannot add them silently.
        ->and(collect($register['types'])->firstWhere('name', 'Maternity Leave')['is_long_leave'])->toBeTrue()
        ->and(collect($register['types'])->firstWhere('name', 'Casual Leave')['is_long_leave'])->toBeFalse();
});

/* --------------------------- working-day counting --------------------------------- */

it('counts only working days and skips holidays', function (): void {
    lvEmployee('E-15');
    $type = lvType('Earned Leave', 14);
    // Sun–Thu week. 2026-11-05 is a Thursday, 11-06 Friday, 11-07 Saturday, 11-08 Sunday.
    lvPolicy($type, ['day_count_mode' => 'working_days', 'working_days' => [0, 1, 2, 3, 4]]);

    $days = (new LeaveBalanceService)->days('E-15', $type->id, '2026-11-05', '2026-11-08');

    // Thu + Sun = 2; Fri and Sat are the weekend.
    expect($days)->toBe(2.0);
});

it('charges a half day as half a day', function (): void {
    lvEmployee('E-16');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    expect((new LeaveBalanceService)->days('E-16', $type->id, '2026-05-05', '2026-05-05', true))->toBe(0.5);
});

it('refuses a half day spread over more than one date', function (): void {
    lvEmployee('E-17');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    expect(fn () => (new LeaveBalanceService)->days('E-17', $type->id, '2026-05-05', '2026-05-06', true))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('refuses an application that crosses two leave years', function (): void {
    lvEmployee('E-18');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    expect(fn () => (new LeaveBalanceService)->days('E-18', $type->id, '2026-12-30', '2027-01-02'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('says which dates in the range cost nothing', function (): void {
    lvEmployee('E-19');
    $type = lvType('Earned Leave', 14);
    lvPolicy($type, ['day_count_mode' => 'working_days', 'working_days' => [0, 1, 2, 3, 4]]);

    $preview = (new LeaveBalanceService)->previewDays('E-19', $type->id, '2026-11-05', '2026-11-08');

    expect($preview['chargeable'])->toBe(2)
        ->and($preview['excluded'])->toBe(2)
        ->and($preview['days'])->toBe(2.0)
        ->and(collect($preview['dates'])->firstWhere('date', '2026-11-06')['excluded'])->toBe('weekend');
});

/* ------------------------------ the workspace reads ------------------------------- */

it('builds the desk without a query error and reports the year state', function (): void {
    lvEmployee('E-20');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);
    lvRequest('E-20', $type, '2026-03-02', '2026-03-03', 2, 'Approved');

    $overview = (new LeaveWorkspaceService(new LeaveBalanceService))->overview(2026);

    expect($overview['year'])->toBe(2026)
        ->and($overview['pending_total'])->toBe(0)
        ->and($overview['types'])->toHaveCount(1)
        ->and($overview['types'][0]['approved'])->toBe(2.0)
        ->and($overview['series'])->toHaveCount(12)
        ->and($overview['year_state']['previous_closed'])->toBeFalse();
});

it('describes one application with the balance it would cost', function (): void {
    lvEmployee('E-21');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);
    $leave = lvRequest('E-21', $type, '2026-07-06', '2026-07-07', 2, 'Pending');

    $detail = (new LeaveWorkspaceService(new LeaveBalanceService))->requestDetail($leave->load('employee', 'leaveType'));

    // A pending application already reserves its days, so the balance shown nets them out and
    // approving it moves nothing further. That is the point of reserving: the second approval cannot
    // spend days a first pending application has already claimed.
    expect($detail['request']['days'])->toBe(2.0)
        ->and($detail['balance']['entitlement'])->toBe(10.0)
        ->and($detail['balance']['pending'])->toBe(2.0)
        ->and($detail['balance']['available'])->toBe(8.0)
        ->and($detail['position']['sufficient'])->toBeTrue()
        ->and($detail['position']['after_approval'])->toBe(8.0)
        ->and($detail['stages'])->toBe(['HR']);
});

it('flags an application the balance would overdraw', function (): void {
    lvEmployee('E-22');
    $type = lvType('Casual Leave', 2);
    lvPolicy($type);
    $leave = lvRequest('E-22', $type, '2026-07-06', '2026-07-11', 6, 'Pending');

    $detail = (new LeaveWorkspaceService(new LeaveBalanceService))->requestDetail($leave->load('employee', 'leaveType'));

    // 2 days entitled, 6 asked for: the application has reserved its 6, so the balance already reads
    // −4 and the shortfall is 4 days. (Asserted against the unclamped figure — the clamped one is 0
    // and used to make this look covered.)
    expect($detail['position']['sufficient'])->toBeFalse()
        ->and($detail['position']['shortfall'])->toBe(4.0)
        ->and($detail['balance']['available_raw'])->toBe(-4.0);
});

it('groups the approval queue by how long it has waited', function (): void {
    lvEmployee('E-23');
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);
    $old = lvRequest('E-23', $type, '2026-08-03', '2026-08-04', 2, 'Pending');
    LeaveRequest::query()->whereKey($old->id)->update(['created_at' => now()->subDays(6)]);

    $queue = (new LeaveWorkspaceService(new LeaveBalanceService))->approvalQueue();

    expect($queue['total'])->toBe(1)
        ->and($queue['days_at_stake'])->toBe(2.0)
        ->and($queue['overdue'])->toBe(1);
});

it('prices encashment at the component the policy names', function (): void {
    lvEmployee('E-24');
    lvSalary('E-24', 30000, 45000);
    $type = lvType('Earned Leave', 14);
    lvPolicy($type, ['is_carry_forward' => true, 'max_carry_days' => 10, 'is_encashable' => true, 'encash_max_days' => 6, 'encash_salary_component' => 'basic']);

    $preview = (new LeaveYearEndService(new LeaveBalanceService))->encashmentPreview('E-24', 2026);

    // 6 days × (30,000 / 30) = 6,000 — capped by encash_max_days, not by the 14 available.
    expect($preview['lines'])->toHaveCount(1)
        ->and($preview['lines'][0]['days'])->toBe(6.0)
        ->and($preview['lines'][0]['per_day'])->toBe(1000.0)
        ->and($preview['total_amount'])->toBe(6000.0);
});

it('reports what closing the year would carry and what it would throw away', function (): void {
    lvEmployee('E-25', ['joining_date' => '2020-01-01']);
    $type = lvType('Casual Leave', 10);
    lvPolicy($type);

    $preview = (new LeaveYearEndService(new LeaveBalanceService))->preview(2026);
    $line = collect($preview['types'])->firstWhere('leave_type', 'Casual Leave');

    expect($line['employees'])->toBe(1)
        ->and($line['entitlement'])->toBe(10.0)
        ->and($line['carry'])->toBe(0.0)
        // No carry policy on a 10-day type means all 10 unused days lapse.
        ->and($line['lapsed'])->toBe(10.0);
});

/*
 * Four defects found by looking at the rendered screens rather than the data, added after the first
 * 25 tests went green. Each one produced a figure that was arithmetically fine and read as a lie.
 */

it('never reports a decision latency that runs backwards', function (): void {
    lvEmployee('E-30');
    $type = lvType('Sick Leave', 16);
    lvPolicy($type);
    $leave = lvRequest('E-30', $type, '2026-05-04', '2026-05-05', 2, 'Approved');

    // Seed and import rows exist whose approved_at precedes created_at. A plain AVERAGE over them
    // printed "-271 days from application to decision" on the report.
    LeaveRequest::query()->whereKey($leave->id)->update([
        'created_at' => '2026-05-10 09:00:00',
        'approved_at' => '2026-05-02 09:00:00',
    ]);

    $totals = (new LeaveWorkspaceService(new LeaveBalanceService))->report(2026)['totals'];

    expect($totals['average_decision_days'])->toBe(0.0)
        ->and($totals['impossible_timestamps'])->toBe(1)
        ->and($totals['decisions'])->toBe(1);
});

it('separates the spendable entitlement pool from long leave', function (): void {
    lvEmployee('E-31');
    $casual = lvType('Casual Leave', 10);
    lvPolicy($casual);
    $maternity = lvType('Maternity Leave', 180);
    lvPolicy($maternity);

    $totals = (new LeaveWorkspaceService(new LeaveBalanceService))->report(2026)['totals'];

    // One employee: 10 ordinary days and 180 long-leave days. The headline the report shows must be
    // the 10, or the figure reads as "this hospital grants 190 days of ordinary leave".
    expect($totals['entitlement_pool_spendable'])->toBe(10.0)
        ->and($totals['entitlement_pool_long'])->toBe(180.0)
        ->and($totals['entitlement_pool'])->toBe(190.0);
});

it('carries the unclamped balance through to the detail page', function (): void {
    lvEmployee('E-32');
    $type = lvType('Casual Leave', 2);
    lvPolicy($type);
    lvRequest('E-32', $type, '2026-03-02', '2026-03-04', 4, 'Approved');

    $detail = (new LeaveWorkspaceService(new LeaveBalanceService))->requestDetail(
        LeaveRequest::query()->first()->load('employee', 'leaveType'),
    );

    // 2 entitled, 4 taken: the screen shows 0 because an employee cannot take a negative number of
    // days, but the record has to keep the −2 so an approver can see the overdraw rather than a
    // clean zero balance.
    expect($detail['balance']['available'])->toBe(0.0)
        ->and($detail['balance']['available_raw'])->toBe(-2.0)
        ->and($detail['position']['sufficient'])->toBeFalse()
        ->and($detail['position']['shortfall'])->toBe(2.0);
});

it('warns on the desk about the days closing the year would lapse', function (): void {
    lvEmployee('E-33', ['joining_date' => '2020-01-01']);
    $type = lvType('Earned Leave', 14);
    lvPolicy($type);

    $preview = (new LeaveWorkspaceService(new LeaveBalanceService))->overview(2026)['lapse_preview'];

    // `types` carries the lapsing types with their own figures — the desk names the three biggest and
    // says how much of the total they account for, so a bare list of names would not be enough.
    expect($preview['days'])->toBe(14.0)
        ->and($preview['days_spendable'])->toBe(14.0)
        ->and($preview['days_long'])->toBe(0.0)
        ->and($preview['carry'])->toBe(0.0)
        ->and($preview['types'])->toHaveCount(1)
        ->and($preview['types'][0]['name'])->toBe('Earned Leave')
        ->and($preview['types'][0]['lapsed'])->toBe(14.0)
        ->and($preview['types'][0]['is_carry_forward'])->toBeFalse()
        ->and($preview['already_closed'])->toBe(0);
});

it('quotes the same spendable pool on the desk as on the report', function (): void {
    lvEmployee('E-34');
    lvPolicy(lvType('Casual Leave', 10));
    lvPolicy(lvType('Maternity Leave', 180));

    $service = new LeaveWorkspaceService(new LeaveBalanceService);
    $desk = $service->overview(2026);
    $report = $service->report(2026)['totals'];

    // The desk used to print 190 days "granted across 1 staff" — the pooled total, which is the size
    // of the benefit map rather than anything the employee can spend. Both pages now say 10.
    expect($desk['spendable_pool'])->toBe(10.0)
        ->and($desk['long_leave_pool'])->toBe(180.0)
        ->and($desk['totals']['entitlement_pool'])->toBe(190.0)
        ->and($report['entitlement_pool_spendable'])->toBe($desk['spendable_pool'])
        ->and($report['entitlement_pool_long'])->toBe($desk['long_leave_pool']);
});

it('marks a lapsed long-leave entitlement as long leave, not as leave somebody lost', function (): void {
    lvEmployee('E-35', ['joining_date' => '2020-01-01']);
    lvPolicy(lvType('Maternity Leave', 180));

    $preview = (new LeaveWorkspaceService(new LeaveBalanceService))->overview(2026)['lapse_preview'];

    expect($preview['days'])->toBe(180.0)
        ->and($preview['days_long'])->toBe(180.0)
        // Nothing of that 180 is ordinary leave, so the heading the desk leads with reads zero rather
        // than announcing a 180-day emergency for an entitlement almost nobody draws on.
        ->and($preview['days_spendable'])->toBe(0.0)
        ->and($preview['types'])->toHaveCount(1)
        ->and($preview['types'][0]['is_long_leave'])->toBeTrue();
});

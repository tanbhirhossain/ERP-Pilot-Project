<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollSalarySheetService;
use Modules\PAYROLL\Services\ProvidentFundService;

/*
 * `travelTo()` freezes the clock for the whole application, and nothing puts it back on its own —
 * the rest of this suite (SALES included) runs on real time and a frozen 2026 leaks into it. The
 * existing one-employee flow test resets the clock here for the same reason; without this line two
 * POS tests three directories away failed on a clock that was never theirs.
 */
afterEach(function (): void {
    // `travelTo()` freezes the clock through Illuminate\Support\Carbon, which is a subclass with
    // its own static — clearing Carbon\Carbon alone leaves Laravel still frozen at 2026. `travelBack()`
    // is the documented inverse and clears whichever one the helper actually set; the explicit clear
    // afterwards covers a test that set the clock by hand.
    $this->travelBack();
    Carbon::setTestNow(null);
    \Illuminate\Support\Carbon::setTestNow(null);
});

/**
 * Noorjahan — one employee, from the day she is hired to the day she is paid.
 *
 * Every other test in this suite starts somewhere in the middle of the pipeline: the flow test
 * builds the employee with `DB::table()->insert()`, the payroll tests hand the engine a salary
 * that was already there. That is the wrong half of the story for the person who actually runs
 * this system, because the two questions they get asked are "when I add somebody from the
 * Employees screen, does payroll actually pick her up?" and "where do I go to see her payslip?"
 *
 * So this walks the real path, through the HTTP layer wherever a screen exists:
 *
 *   1. HR adds her on /hrm/employees/create        → employees + salary + policy rows, biometric mapped
 *   2. HR gives her a shift and a month of duties  → /hrm/shifts, /hrm/duties
 *   3. She punches; the engine pairs the logs      → attendance_pairs, attendance_dailies
 *   4. The reconciler fills the days she missed    → Absent
 *   5. She applies for one unpaid day and one paid  → /hrm/leaves, /hrm/leaves/{id}/approve
 *   6. HR approves her overtime                    → /hrm/overtimes/{id}/approve
 *   7. Payroll runs the month with punishments on  → /payroll/salary-sheets
 *   8. The sheet is released, then paid            → /payroll/salary-sheets/{id}/release, /payroll/slips/{id}
 *   9. She signs in and reads her own payslip      → /hrm/my/payslips/{id}
 *
 * The arithmetic is checked at the end against the slip's own line items, not against a number
 * copied from a previous run.
 */
function lifecycleActor(string $email = 'lifecycle.admin@example.test'): User
{
    $admin = User::query()->where('email', $email)->first();
    if ($admin !== null) {
        return $admin;
    }

    // Super Admin carries the wildcard permission, which is what every route in this walk is gated
    // on. Using it here keeps the test about the flow rather than about re-deriving the permission
    // matrix in a test file.
    $admin = User::create([
        'name' => 'Lifecycle Admin',
        'email' => $email,
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('Super Admin');

    return $admin;
}

/** A second pair of hands: payroll refuses requester == approver on increments and loans alike. */
function lifecycleApprover(): User
{
    $approver = User::query()->where('email', 'lifecycle.approver@example.test')->first();
    if ($approver === null) {
        $approver = User::create([
            'name' => 'Lifecycle Approver',
            'email' => 'lifecycle.approver@example.test',
            'password' => bcrypt('secret-password'),
            'email_verified_at' => now(),
        ]);
        $approver->assignRole('Super Admin');
    }

    return $approver;
}

function lifecycleBoot(): User
{
    test()->seed(SuperAdminSeeder::class);

    $superAdmin = User::query()->where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();
    $actor = lifecycleActor();
    // Both accounts hold Super Admin, because adding an employee uses `hrm.employees.manage` while
    // approving a loan refuses the requester — two people, as the real desk has.
    $superAdmin->assignRole('Super Admin');

    return $actor;
}

/** One project bank, because a BEFTN advice refuses to be generated without one. */
function lifecycleBank(): void
{
    if (\Modules\ACCOUNTS\Models\Bank::query()->exists()) {
        return;
    }

    $ledger = \Modules\ACCOUNTS\Models\Account::query()->create([
        'code' => '100777',
        'name' => 'Lifecycle disbursement bank',
        'account_type' => 'asset',
        'nature' => 'debit',
        'is_active' => true,
    ]);

    \Modules\ACCOUNTS\Models\Bank::query()->create([
        'account_id' => $ledger->id,
        'name' => 'Lifecycle disbursement bank',
        'code' => 'LIFE-BANK',
        'bank_name' => 'Dutch-Bangla Bank',
        'branch_name' => 'Motijheel',
        'account_no' => '1931030788700',
        'account_holder' => 'ERP Pilot Hospital',
        'currency' => 'BDT',
        'is_default' => true,
        'is_active' => true,
    ]);
}

function lifecycleFiscalYear(int $year = 2026): void
{
    if (\Modules\ACCOUNTS\Models\FiscalYear::query()->where('start_date', '<=', $year.'-01-01')->where('end_date', '>=', $year.'-12-31')->exists()) {
        return;
    }

    \Modules\ACCOUNTS\Models\FiscalYear::query()->create([
        'name' => 'FY '.$year.'-'.($year + 1),
        'code' => 'FY'.substr((string) $year, 2).'-'.substr((string) ($year + 1), 2),
        'start_date' => $year.'-01-01',
        'end_date' => ($year + 1).'-12-31',
        'status' => 'open',
    ]);
}

test('one employee is hired, rostered, punched, granted leave, paid and can read her own payslip', function (): void {
    @set_time_limit(0);
    // The month is closed from the other side: payroll for October is run on 2 November, which is
    // also the first day the reconciler is willing to classify 31 October.
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    // ── 1. Hiring her on the screen HR actually uses ────────────────────────────────────────────
    //
    // Everything below is what the Create Employee form posts. If the form and this list ever drift
    // apart, the failure lands here rather than on the first real new joiner.
    $hire = $this->post(route('hrm.employees.store'), [
        'employee_id' => 'LIFE-001',
        'biometric_id' => '9001',
        'first_name' => 'Noorjahan',
        'last_name' => 'Akter',
        'email' => 'noorjahan.akter@example.test',
        'phone' => '01711111111',
        'gender' => 'Female',
        'date_of_birth' => '1994-06-12',
        'joining_date' => '2026-10-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'department_id' => \Modules\HRM\Models\Department::factory()->create(['name' => 'Lifecycle Nursing'])->id,
        'designation_id' => \Modules\HRM\Models\Designation::factory()->create(['name' => 'Staff Nurse'])->id,
        'allow_overtime' => true,
        'allow_bonus' => true,
        'policy' => [
            'shift_type' => 'Fixed',
            'grace_time_in' => 15,
            'grace_time_out' => 15,
            'is_punishable' => true,
            'duty_hours' => 8,
            'auto_shift' => false,
            'fallback_off_day' => 5,
        ],
        'salary' => [
            'basic_salary' => 20000,
            'gross_salary' => 30000,
            'house_rent' => 6000,
            'medical_allowance' => 1500,
            'other_allowance' => 2500,
            'net_salary' => 30000,
            'payment_method' => 'bank',
            'bank_name' => 'Dutch-Bangla Bank',
            'bank_account_no' => '1931030788700',
            'effective_from' => '2026-10-01',
            'is_current' => true,
        ],
    ]);
    $hire->assertRedirect(route('hrm.employees.index'));
    $hire->assertSessionHas('success');

    $employee = Employee::query()->where('employee_id', 'LIFE-001')->firstOrFail();
    expect($employee->full_name)->toBe('Noorjahan Akter')
        ->and($employee->status)->toBe('Active')
        ->and((bool) $employee->is_active)->toBeTrue();

    // The three rows payroll reads were all written by that one form submission.
    expect(DB::table('employee_salaries')->where('employee_id', 'LIFE-001')->where('is_current', 1)->value('gross_salary'))->toEqual(30000)
        ->and((bool) DB::table('employee_policies')->where('employee_id', 'LIFE-001')->value('is_punishable'))->toBeTrue()
        ->and((int) DB::table('employee_fallback_off_days')->where('employee_id', 'LIFE-001')->value('day_of_week'))->toBe(5);

    // Her biometric id is mapped for the device, which is how a punch finds her at all.
    expect(DB::table('device_users')->where('device_user_id', '9001')->value('employee_id'))->toBe('LIFE-001');

    // Hiring provisions her sign-in on the spot: one linked user on her address, temporary
    // password, forced change. There is no separate "create the account" step anymore.
    expect($employee->user_id)->not->toBeNull();
    $provisioned = User::find($employee->user_id);
    expect($provisioned->email)->toBe('noorjahan.akter@example.test')
        ->and((bool) $provisioned->force_password_change)->toBeTrue()
        ->and($provisioned->provisioned_employee_code)->toBe('LIFE-001');

    // ── 2. A shift, and the October duty roster ────────────────────────────────────────────────
    $this->post(route('hrm.shifts.store'), [
        'shift_name' => 'Lifecycle Day Shift',
        'start_time' => '09:00',
        'end_time' => '17:00',
        'punch_in_window_start' => '08:00',
        'punch_in_window_end' => '10:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ])->assertRedirect(route('hrm.shifts.index'));

    $shift = RosterShift::query()->where('shift_name', 'Lifecycle Day Shift')->firstOrFail();

    // Rostered on working days only — Friday (weekday 5) is her contracted off day and the 20th is a
    // public holiday, so no duty is issued for either. The reconciler and the payroll provider both
    // have to arrive at "not expected in" from three different sources.
    $workingDates = collect(range(1, 31))
        ->map(fn (int $day) => Carbon::create(2026, 10, $day))
        ->reject(fn (Carbon $date) => $date->dayOfWeek === 5 || $date->day === 20)
        ->map(fn (Carbon $date) => $date->toDateString())
        ->values();

    foreach ($workingDates as $date) {
        $this->post(route('hrm.duties.store'), [
            'employee_id' => 'LIFE-001',
            'shift_id' => $shift->id,
            'roster_date' => $date,
            'status' => 'Scheduled',
        ])->assertRedirect(route('hrm.duties.index'));
    }

    expect(DB::table('roster_duties')->where('employee_id', 'LIFE-001')->count())->toBe($workingDates->count());

    DB::table('hrm_holidays')->insert([
        'holiday_date' => '2026-10-20',
        'name' => 'Durga Puja',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ── 3. She punches ────────────────────────────────────────────────────────────────────────
    //
    // She is in on the 1st, and on 5-8 October. On her first day she stays until 19:00, which is the
    // two hours of overtime this payslip will carry; on the 6th she punches in half an hour late —
    // past the 15-minute grace — so it is a Late day and one punishment day. Every one of these is a
    // rostered working day, so nothing here is paid as extra duty by accident.
    $punches = [
        ['2026-10-01 09:00:00', '2026-10-01 19:00:00'],
        ['2026-10-05 09:00:00', '2026-10-05 17:00:00'],
        ['2026-10-06 09:30:00', '2026-10-06 17:10:00'],
        ['2026-10-07 09:00:00', '2026-10-07 17:00:00'],
        ['2026-10-08 08:58:00', '2026-10-08 17:05:00'],
    ];

    foreach ($punches as [$in, $out]) {
        // The device posts to the same endpoint the device-user mapping feeds, so this goes through
        // the request layer and the log ends up exactly as a real device would leave it.
        // `employee_id` is what the manual-entry screen sends: the device knows a ZK user number,
        // HR types the employee. The processing queue only ever walks logs that resolve to a person,
        // which is why an unmapped punch waits as an orphan instead of inventing an attendance row.
        $this->post(route('hrm.attendance.logs.store'), [
            'device_user_id' => '9001',
            'employee_id' => 'LIFE-001',
            'punch_time' => $in,
            'punch_type' => 'AUTO',
        ])->assertRedirect();

        $this->post(route('hrm.attendance.logs.store'), [
            'device_user_id' => '9001',
            'employee_id' => 'LIFE-001',
            'punch_time' => $out,
            'punch_type' => 'AUTO',
        ])->assertRedirect();
    }

    expect(DB::table('attendance_logs')->count())->toBe(10);

    $this->post(route('hrm.attendance.logs.process'), ['limit' => 100])->assertRedirect();

    $dailies = AttendanceDaily::query()->where('employee_id', 'LIFE-001')
        ->get()->keyBy(fn ($row) => $row->duty_date->toDateString());

    expect($dailies)->toHaveCount(5)
        // Two hours past her 17:00 shift end: overtime, and the day is not late.
        ->and((float) $dailies['2026-10-01']->total_overtime_hours)->toBe(2.0)
        ->and($dailies['2026-10-01']->status)->not->toBe('Late')
        // Thirty minutes against a 15-minute grace window.
        ->and($dailies['2026-10-06']->status)->toBe('Late');

    // ── 4. Filling in the days she was not there ─────────────────────────────────────────────
    //
    // 3, 4 and the rest of the month have a duty but no punch. Reconciling writes the Absent rows
    // that payroll will dock — and it must leave her Fridays, the holiday, and her approved leave
    // alone, which is the whole reason this step runs before payroll rather than after.
    app(AttendanceDayReconciliationService::class)->reconcile('2026-10-01', '2026-10-31');

    $absentDates = AttendanceDaily::query()->where('employee_id', 'LIFE-001')
        ->where('status', 'Absent')->pluck('duty_date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString());

    // 25 rostered days (31, less five Fridays, less the holiday) minus the five she punched.
    expect($absentDates->count())->toBe(20)
        ->and($absentDates)->not->toContain('2026-10-20')
        ->and($absentDates->contains(fn (string $date) => Carbon::parse($date)->dayOfWeek === 5))->toBeFalse();

    // ── 5. Leave: one unpaid day, one paid day ───────────────────────────────────────────────
    $leaveType = fn (string $name, string $code, int $daysPerYear, bool $paid): array => [
        'name' => $name,
        'code' => $code,
        'days_per_year' => $daysPerYear,
        'is_paid' => $paid,
        'is_active' => true,
        // The type form asks for its policy in the same submission; these are its defaults.
        'day_count_mode' => 'calendar_days',
        'accrual_mode' => 'annual',
        'proration_mode' => 'none',
        'approval_steps' => ['HR'],
        'is_carry_forward' => false,
        'is_encashable' => false,
        'encash_salary_component' => 'basic',
    ];

    // Five days of entitlement that are simply not paid: the balance check passes, the payslip
    // still docks them, which is the distinction this test is built on.
    $this->post(route('hrm.leave-types.store'), $leaveType('Lifecycle Unpaid Leave', 'LIFE-UL', 5, false))
        ->assertRedirect(route('hrm.leave-types.index'))
        ->assertSessionHasNoErrors();
    $this->post(route('hrm.leave-types.store'), $leaveType('Lifecycle Casual Leave', 'LIFE-CL', 12, true))
        ->assertRedirect(route('hrm.leave-types.index'))
        ->assertSessionHasNoErrors();

    $unpaidType = LeaveType::query()->where('code', 'LIFE-UL')->firstOrFail();
    $paidType = LeaveType::query()->where('code', 'LIFE-CL')->firstOrFail();

    $this->post(route('hrm.leaves.store'), [
        'employee_id' => 'LIFE-001',
        'leave_type_id' => $unpaidType->id,
        'from_date' => '2026-10-03',
        'to_date' => '2026-10-04',
        'reason' => 'Family emergency, unpaid.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('hrm.leaves.store'), [
        'employee_id' => 'LIFE-001',
        'leave_type_id' => $paidType->id,
        'from_date' => '2026-10-12',
        'to_date' => '2026-10-12',
        'reason' => 'Casual leave, paid.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $unpaidLeave = DB::table('leave_requests')->where('leave_type_id', $unpaidType->id)->first();
    $paidLeave = DB::table('leave_requests')->where('leave_type_id', $paidType->id)->first();

    expect($unpaidLeave)->not->toBeNull()->and($unpaidLeave->status)->toBe('Pending');

    $this->post(route('hrm.leaves.approve', $unpaidLeave->id))->assertRedirect();
    $this->post(route('hrm.leaves.approve', $paidLeave->id))->assertRedirect();

    expect(DB::table('leave_requests')->where('id', $unpaidLeave->id)->value('status'))->toBe('Approved')
        ->and(DB::table('leave_requests')->where('id', $paidLeave->id)->value('status'))->toBe('Approved');

    // Approval stamps the attendance calendar, so the two unpaid days stop being Absent and the
    // paid day is a day she was legitimately away.
    $status = fn (string $date): ?string => DB::table('attendance_dailies')
        ->where('employee_id', 'LIFE-001')->whereDate('duty_date', $date)->value('status');

    expect($status('2026-10-03'))->toBe('On Leave')
        ->and($status('2026-10-04'))->toBe('On Leave')
        ->and($status('2026-10-12'))->toBe('On Leave');

    // ── 6. Overtime, approved with the reviewed hours ────────────────────────────────────────
    $this->post(route('hrm.overtimes.sync-attendance'), ['kind' => 'Overtime'])->assertRedirect();

    $overtime = DB::table('roster_overtimes')->where('employee_id', 'LIFE-001')->where('overtime_type', 'Overtime')->first();
    expect($overtime)->not->toBeNull()
        ->and((float) $overtime->hours)->toBe(2.0);

    $this->post(route('hrm.overtimes.approve', $overtime->id), [
        'hours' => 1.5,
        'comments' => 'Approved 1.5 of the 2 hours worked.',
    ])->assertRedirect();

    expect(DB::table('roster_overtimes')->where('id', $overtime->id)->value('status'))->toBe('Approved');

    // ── 7. Configuration the first month always needs ───────────────────────────────────────
    lifecycleBank();
    lifecycleFiscalYear(2026);

    // Provident fund: 5% of basic from both sides. Configured on the PF screen, allocated to her.
    $this->post(route('payroll.pf.policies.store'), [
        'name' => 'Lifecycle PF 5%',
        'employee_contribution_percent' => 5,
        'employer_contribution_percent' => 5,
        'minimum_service_months' => 0,
        'contribution_base' => 'basic',
        'effective_from' => '2026-01-01',
    ])->assertRedirect();

    $policyId = (int) DB::table('pf_policies')->where('name', 'Lifecycle PF 5%')->value('id');
    $this->post(route('payroll.pf.allocate'), [
        'scope' => 'single',
        'policy_id' => $policyId,
        'employee_id' => 'LIFE-001',
    ])->assertRedirect();

    expect(DB::table('pf_accounts')->where('employee_id', 'LIFE-001')->exists())->toBeTrue();

    // Income tax: a flat 750 a month, which keeps the payslip arithmetic readable.
    $this->put(route('payroll.tax.update'), [
        'name' => 'Lifecycle flat tax',
        'mode' => 'fixed',
        'fixed_monthly_tax' => 750,
        'annual_exemption' => 0,
        'taxable_salary_percent' => 100,
        'effective_from' => '2026-01-01',
        'reason' => 'Flat monthly withholding used by the lifecycle flow test.',
    ])->assertRedirect();

    // Payroll settings: 30-day divisor, three punishment days per salary day, no cutlist gate.
    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT',
        'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1,
        'extra_duty_multiplier' => 1,
        'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30,
        'missing_attendance_policy' => 'waive',
        // The approval role must exist: the settings screen refuses a step nobody can act on, which
        // is exactly the failure that would otherwise hide until a loan sits in a queue for ever.
        'loan_approval_steps_text' => 'Payroll=Super Admin',
        'cutlist_review_required' => false,
    ])->assertSessionHasNoErrors();

    expect(app(\Modules\PAYROLL\Services\PayrollSettingsService::class)->current()->missing_attendance_policy)->toBe('waive');

    // ── 8. The month runs ───────────────────────────────────────────────────────────────────
    Bus::fake();

    $sheetResponse = $this->post(route('payroll.sheets.store'), [
        'pay_year' => 2026,
        'pay_month' => 10,
        'include_punishments' => true,
    ]);

    $sheet = PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    $sheetResponse->assertRedirect(route('payroll.sheets.show', $sheet));

    // The job is queued behind a fake bus in the test; running the action directly is the same work.
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    expect($sheet->status)->toBe('ready')
        ->and((int) $sheet->employee_count)->toBe(1)
        ->and($sheet->slips()->count())->toBe(1);

    $slip = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->firstOrFail();

    // ── 9. The payslip, line by line ───────────────────────────────────────────────────────
    $b = $slip->breakdown_json;

    // She was hired on 1 October, so the month is paid in full — the proration factor proves it
    // rather than the gross merely looking right.
    expect((float) $b['salary_proration']['factor'])->toBe(1.0)
        ->and((int) $b['salary_proration']['payable_days'])->toBe(31)
        ->and((float) $slip->basic_salary)->toBe(20000.0)
        ->and((float) $b['period_base_components']['gross'])->toBe(30000.0);

    // Absence: the 20 days the reconciler marked Absent, less the 3 days leave later rewrote as
    // "On Leave", plus the 2 unpaid leave days she applied for = 19 days at gross / 30 = 1,000 a day.
    // The paid day on 12 October is on the calendar as On Leave and costs her nothing — which is
    // the whole difference between the two applications.
    expect((float) $b['cutlist']['unpaid_leave_days'])->toBe(19.0)
        ->and((float) $b['cutlist']['unpaid_leave'])->toBe(19000.0);

    // Punishment: one Late day, floor(1 / 3) = 0 salary days. She was late once and it did not cost
    // her a day's pay — the threshold is doing its job.
    expect((int) $b['cutlist']['late_count'])->toBe(1)
        ->and((int) $b['cutlist']['punishment_days'])->toBe(1)
        ->and((float) $b['cutlist']['punishment'])->toBe(0.0);

    // Overtime: 1.5 approved hours at basic / (30 x 8) = 83.3333 an hour, 125 in total.
    expect((float) $b['cutlist']['overtime_hours'])->toBe(1.5)
        ->and((float) $b['cutlist']['overtime_pay'])->toBe(125.0);

    expect((float) $b['tax']['monthly_tax'])->toBe(750.0)
        ->and((float) $b['provident_fund']['employee_contribution'])->toBe(1000.0);

    // The identity, computed from the slip's own lines.
    $earnings = (float) $b['period_base_components']['gross'] + collect($b['benefits'])->sum('amount');
    $deductions = collect($b['recurring_deductions'])->sum('amount')
        + (float) $b['tax']['monthly_tax']
        + (float) $b['provident_fund']['employee_contribution']
        + (float) $b['cutlist']['unpaid_leave']
        + (float) $b['cutlist']['punishment']
        + (float) $b['cutlist']['missing_punch'];

    expect(round($earnings, 2))->toBe(30125.0)
        ->and(round($deductions, 2))->toBe(20750.0)
        ->and(round((float) $slip->gross_salary, 2))->toBe(round($earnings, 2))
        ->and(round((float) $slip->total_deductions, 2))->toBe(round($deductions, 2))
        ->and(round((float) $slip->net_salary, 2))->toBe(9375.0);

    // ── 10. Release, then pay ───────────────────────────────────────────────────────────────
    $this->post(route('payroll.sheets.release', $sheet))->assertRedirect();

    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);
    expect($sheet->status)->toBe('released');

    // The accrual balances: gross + employer PF on one side, the same on the other.
    $accrual = DB::table('payroll_journal_posts')->where('salary_sheet_id', $sheet->id)->where('posting_type', 'salary_accrual')->first();
    expect($accrual)->not->toBeNull()
        ->and(round((float) $accrual->total_debit, 2))->toBe(round((float) $accrual->total_credit, 2))
        ->and(round((float) $accrual->total_debit, 2))->toBe(round((float) $slip->gross_salary + 1000.0, 2));

    // Paying the slip from its own page — the button a payroll clerk presses.
    $this->post(route('payroll.slips.payment-status', $slip), ['payment_status' => 'paid'])->assertRedirect();

    $slip->refresh();
    expect($slip->payment_status)->toBe('paid')
        ->and($slip->payment_method)->toBe('bank')
        ->and($slip->paid_at)->not->toBeNull();

    // The provident fund followed the payment, as it should: two ledger entries, both contributions.
    $ledger = DB::table('pf_ledgers')->where('reference_type', 'payroll_slip')->where('reference_id', $slip->id)->get();
    expect($ledger)->toHaveCount(2)
        ->and(round((float) $ledger->sum('amount'), 2))->toBe(2000.0);

    // ── 11. She signs in and reads it herself ───────────────────────────────────────────────
    // Her login is the one hiring provisioned. The temporary password was shown once, at
    // hire, and cannot be read back — so the test stands in HR's shoes and sets a known
    // one the way the reset command would, including clearing the forced change.
    $herAccount = User::find(Employee::query()->find($employee->id)->user_id);
    $herAccount->forceFill([
        'password' => bcrypt('secret-password'),
        'force_password_change' => false,
        'email_verified_at' => now(),
    ])->save();

    $this->actingAs($herAccount);
    $this->get(route('hrm.my.dashboard'))->assertOk();
    $this->get(route('hrm.my.payslips'))->assertOk();
    $this->get(route('hrm.my.payslip', $slip->id))->assertOk();

    // And the scoping still holds: she cannot open anyone else's.
    $colleague = Employee::query()->create([
        'employee_id' => 'LIFE-002', 'first_name' => 'Colleague', 'last_name' => 'Person',
        'full_name' => 'Colleague Person', 'status' => 'Active', 'is_active' => true,
        'joining_date' => '2026-01-01', 'employment_type' => 'Permanent', 'gender' => 'Male',
    ]);
    $foreignSlip = PayrollSlip::query()->create([
        'salary_sheet_id' => $sheet->id,
        'employee_id' => 'LIFE-002',
        'employee_name' => $colleague->full_name,
        'pay_year' => 2026, 'pay_month' => 10,
        'basic_salary' => 20000, 'gross_salary' => 30000,
        'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => 30000,
        'payment_status' => 'unpaid', 'breakdown_json' => ['version' => 1, 'benefits' => [], 'recurring_deductions' => []],
    ]);

    $this->get(route('hrm.my.payslip', $foreignSlip->id))->assertNotFound();
});

test('an employee hired mid-month is paid pro rata, and a future joiner is left out entirely', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-10-31 18:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    lifecycleBank();
    lifecycleFiscalYear(2026);

    // Hired on the 16th: 16 payable days out of 31.
    $this->post(route('hrm.employees.store'), [
        'employee_id' => 'LIFE-MID',
        'first_name' => 'Mid',
        'last_name' => 'Joiner',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'gender' => 'Male',
        'joining_date' => '2026-10-16',
        'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8],
        'salary' => ['basic_salary' => 20000, 'gross_salary' => 30000, 'net_salary' => 30000, 'effective_from' => '2026-10-16', 'is_current' => true, 'payment_method' => 'cash'],
    ])->assertRedirect();

    // Hired for November: not in October's payroll at all, not even as a zero.
    $this->post(route('hrm.employees.store'), [
        'employee_id' => 'LIFE-NEXT',
        'first_name' => 'Next',
        'last_name' => 'Month',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'gender' => 'Male',
        'joining_date' => '2026-11-05',
        'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8],
        'salary' => ['basic_salary' => 20000, 'gross_salary' => 30000, 'net_salary' => 30000, 'effective_from' => '2026-11-05', 'is_current' => true, 'payment_method' => 'cash'],
    ])->assertRedirect();

    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT', 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30, 'missing_attendance_policy' => 'waive',
        // The approval role must exist: the settings screen refuses a step nobody can act on, which
        // is exactly the failure that would otherwise hide until a loan sits in a queue for ever.
        'loan_approval_steps_text' => 'Payroll=Super Admin', 'cutlist_review_required' => false,
    ])->assertRedirect();

    Bus::fake();
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 10, 'include_punishments' => true]);

    $sheet = PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    $slips = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->get()->keyBy('employee_id');

    expect($slips)->toHaveCount(1)
        ->and($slips->keys()->all())->toBe(['LIFE-MID'])
        ->and($slips->has('LIFE-NEXT'))->toBeFalse();

    $mid = $slips['LIFE-MID'];
    $b = $mid->breakdown_json;

    // 16 of 31 days: 30,000 x 16 / 31 = 15,483.87.
    expect((int) $b['salary_proration']['payable_days'])->toBe(16)
        ->and((int) $b['salary_proration']['days_in_month'])->toBe(31)
        ->and(round((float) $mid->gross_salary, 2))->toBe(15483.87);

    // And nothing was deducted for the 15 days before she existed.
    expect((float) $b['cutlist']['unpaid_leave_days'])->toBe(0.0);
});

test('an employee who resigns leaves the monthly sheet and is never docked after her last day', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-10-31 18:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    lifecycleBank();
    lifecycleFiscalYear(2026);

    // Two nurses hired the same day, one of whom hands in her notice on the 20th with the 31st as
    // her last working day. The other stays.
    foreach ([['LEAVE-A', 'Staying'], ['LEAVE-B', 'Leaving']] as [$code, $surname]) {
        $this->post(route('hrm.employees.store'), [
            'employee_id' => $code,
            'first_name' => $surname,
            'last_name' => 'Nurse',
            'employment_type' => 'Permanent',
            'status' => 'Active',
            'gender' => 'Female',
            'joining_date' => '2026-01-01',
            'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8, 'fallback_off_day' => 5],
            'salary' => ['basic_salary' => 20000, 'gross_salary' => 30000, 'net_salary' => 30000, 'effective_from' => '2026-01-01', 'is_current' => true, 'payment_method' => 'cash'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    $this->post(route('hrm.resignations.store'), [
        'employee_id' => 'LEAVE-B',
        'last_working_date' => '2026-10-31',
        'reason' => 'Accepted a post nearer her family in Khulna.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $resignation = DB::table('employee_resignations')->where('employee_id', 'LEAVE-B')->firstOrFail();

    $this->post(route('hrm.resignations.decision', $resignation->id), [
        'decision' => 'Approved',
        'decision_note' => 'Approved by the nursing superintendent; last working day 31 October.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $approved = DB::table('employee_resignations')->where('id', $resignation->id)->first();
    expect($approved->status)->toBe('Approved')
        ->and($approved->decision_note)->toContain('31 October');

    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT', 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30, 'missing_attendance_policy' => 'waive',
        'loan_approval_steps_text' => 'Payroll=Super Admin', 'cutlist_review_required' => false,
    ])->assertSessionHasNoErrors();

    Bus::fake();
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 10, 'include_punishments' => true]);

    $sheet = PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

    $slips = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->pluck('employee_id')->all();

    // She left on the last day of the month, so October's monthly sheet is not where her money comes
    // from — final settlement is. The payroll says so by leaving her out, rather than paying her
    // twice or docking a salary she no longer earns.
    expect($slips)->toBe(['LEAVE-A'])
        ->and($slips)->not->toContain('LEAVE-B');

    // The reason is queryable, not folklore: an approved resignation whose last working day falls
    // on or before the period end takes the employee out of that period's payroll.
    expect(\Modules\HRM\Models\Employee\EmployeeResignation::query()
        ->where('employee_id', 'LEAVE-B')->where('status', 'Approved')
        ->whereDate('last_working_date', '<=', '2026-10-31')->exists())->toBeTrue();

    // And the attendance side agrees: nothing is docked for the month after she left.
    $after = app(\Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('LEAVE-B', '2026-11-01', '2026-11-30');
    expect($after->unpaidLeaveDays)->toBe(0.0);
});

test('the month cannot be run until the cutlist is signed off, and the bank advice pays only the bank-paid staff', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    lifecycleBank();
    lifecycleFiscalYear(2026);

    // Two attendants, one paid through the bank and one in hand at the counter — the split every
    // month's payroll actually has to produce.
    foreach ([
        ['CUT-BANK', 'Bank', '1931030577059'],
        ['CUT-CASH', 'Cash', null],
    ] as [$code, $surname, $account]) {
        $this->post(route('hrm.employees.store'), [
            'employee_id' => $code,
            'first_name' => $surname,
            'last_name' => 'Attendant',
            'employment_type' => 'Permanent',
            'status' => 'Active',
            'gender' => 'Male',
            'joining_date' => '2026-01-01',
            'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8, 'fallback_off_day' => 5],
            'salary' => [
                'basic_salary' => 15000, 'gross_salary' => 25000, 'net_salary' => 25000,
                'effective_from' => '2026-01-01', 'is_current' => true,
                'payment_method' => $account ? 'bank' : 'cash',
                'bank_name' => $account ? 'Dutch-Bangla Bank' : null,
                'bank_account_no' => $account,
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // The policy the hospital asked for: nothing is paid in a month whose cutlist HR has not signed.
    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT', 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30, 'missing_attendance_policy' => 'waive',
        'loan_approval_steps_text' => 'Payroll=Super Admin', 'cutlist_review_required' => true,
    ])->assertSessionHasNoErrors();

    expect(app(\Modules\PAYROLL\Services\PayrollSettingsService::class)->current()->cutlist_review_required)->toBeTrue();

    // No cutlist yet: the salary sheet is refused, and says where to go.
    $refused = $this->from(route('payroll.sheets.create'))->post(route('payroll.sheets.store'), [
        'pay_year' => 2026, 'pay_month' => 10, 'include_punishments' => true,
    ]);
    $refused->assertSessionHasErrors('payroll_cutlist_id');
    expect(session('errors')->first('payroll_cutlist_id'))->toContain('cutlist');

    expect(PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->exists())->toBeFalse();

    // Generate it from HRM, then review and sign it.
    $this->post(route('payroll.cutlists.generate'), [
        'pay_year' => 2026, 'pay_month' => 10,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cutlist = \Modules\PAYROLL\Models\PayrollCutlist::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    expect($cutlist->status)->toBe('generated')
        ->and($cutlist->items()->count())->toBe(2);

    $this->get(route('payroll.cutlists.show', $cutlist))->assertOk();

    $this->post(route('payroll.cutlists.approve', $cutlist), [
        'note' => 'Reviewed against the October attendance register; both rows agree with HRM.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cutlist->refresh();
    expect($cutlist->status)->toBe('approved')
        ->and($cutlist->approved_at)->not->toBeNull()
        ->and($cutlist->approval_note)->toContain('October attendance register');

    // Now the sheet is allowed, and it is tied to the cutlist that was signed.
    Bus::fake();
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 10, 'include_punishments' => true])
        ->assertRedirect();

    $sheet = PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    expect((int) $sheet->payroll_cutlist_id)->toBe((int) $cutlist->id);

    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    expect($sheet->status)->toBe('ready')
        ->and($sheet->slips()->count())->toBe(2)
        ->and((float) $sheet->gross_total)->toBe(50000.0);

    $this->get(route('payroll.sheets.show', $sheet))->assertOk();
    $this->get(route('payroll.finance.sheets.show', $sheet))->assertOk();

    $this->post(route('payroll.sheets.release', $sheet))->assertRedirect();
    expect(PayrollSalarySheet::query()->findOrFail($sheet->id)->status)->toBe('released');

    // One advice for the bank-paid attendant, one cash sheet for the other — never one file that
    // pretends to have paid both.
    $this->post(route('payroll.bank-advices.generate', $sheet))->assertRedirect()->assertSessionHasNoErrors();

    $advice = \Modules\PAYROLL\Models\PayrollBankAdvice::query()->where('salary_sheet_id', $sheet->id)->firstOrFail();
    expect((int) $advice->employee_count)->toBe(1)
        ->and((float) $advice->total_amount)->toBe(25000.0)
        ->and($advice->items()->pluck('employee_id')->all())->toBe(['CUT-BANK']);

    $this->post(route('payroll.bank-advices.confirm', $advice))->assertRedirect()->assertSessionHasNoErrors();
    expect(\Modules\PAYROLL\Models\PayrollBankAdvice::query()->findOrFail($advice->id)->status)->toBe('confirmed');

    $this->post(route('payroll.bank-advices.paid', $advice))->assertRedirect()->assertSessionHasNoErrors();

    $slips = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->get()->keyBy('employee_id');
    expect($slips['CUT-BANK']->payment_status)->toBe('paid')
        ->and($slips['CUT-BANK']->payment_method)->toBe('bank')
        // The cash-paid attendant is untouched by the bank advice: somebody still has to hand him
        // 25,000 and take his signature.
        ->and($slips['CUT-CASH']->payment_status)->toBe('unpaid');
});

test('an approved increment is what the next payslip is calculated from, with no manual salary editing', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    $this->post(route('hrm.employees.store'), [
        'employee_id' => 'INC-001',
        'first_name' => 'Promoted',
        'last_name' => 'Nurse',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'gender' => 'Female',
        'joining_date' => '2024-01-01',
        'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8, 'fallback_off_day' => 5],
        'salary' => ['basic_salary' => 20000, 'gross_salary' => 30000, 'net_salary' => 30000, 'effective_from' => '2024-01-01', 'is_current' => true, 'payment_method' => 'cash'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    // A 20% raise approved on 30 September, effective 1 October.
    $this->post(route('payroll.increments.store'), [
        'scope' => 'single',
        'employee_id' => 'INC-001',
        'increment_type' => 'percentage',
        'increment_value' => 20,
        'effective_date' => '2026-10-01',
        'reason' => 'Promoted to senior staff nurse after the October review.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $increment = \Modules\PAYROLL\Models\SalaryIncrement::query()->where('employee_id', 'INC-001')->firstOrFail();
    expect($increment->status)->toBe('pending');

    // Nothing has changed in the salary history before approval — the request is only a request.
    expect((float) DB::table('employee_salaries')->where('employee_id', 'INC-001')->where('is_current', 1)->value('gross_salary'))->toBe(30000.0);

    // A raise is approved by somebody other than the person who requested it — the control that
    // stops one officer raising their own pay. The approver holds the same role, just not the same
    // login.
    $this->actingAs(lifecycleApprover())
        ->post(route('payroll.increments.approve', $increment))
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($actor);

    // Approval writes a new, effective-dated salary row and closes the old one the day before.
    $rows = DB::table('employee_salaries')->where('employee_id', 'INC-001')->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        // Stored as a datetime on SQLite; compare the date part.
        ->and(substr((string) $rows[0]->effective_to, 0, 10))->toBe('2026-09-30')
        ->and((bool) $rows[0]->is_current)->toBeFalse()
        ->and(substr((string) $rows[1]->effective_from, 0, 10))->toBe('2026-10-01')
        ->and((float) $rows[1]->basic_salary)->toBe(24000.0)
        ->and((float) $rows[1]->gross_salary)->toBe(36000.0)
        ->and((bool) $rows[1]->is_current)->toBeTrue();

    // September was paid at the old rate and October at the new one: the split is by effective date,
    // which is why payroll threads salary segments rather than a single current figure.
    $september = app(\Modules\PAYROLL\Services\HRM\HrmEmployeePayrollDataProvider::class)
        ->employeesForPayroll('2026-09-01', '2026-09-30')->firstWhere(fn ($e) => $e->employeeId === 'INC-001');
    $october = app(\Modules\PAYROLL\Services\HRM\HrmEmployeePayrollDataProvider::class)
        ->employeesForPayroll('2026-10-01', '2026-10-31')->firstWhere(fn ($e) => $e->employeeId === 'INC-001');

    expect(round((float) $september->grossSalary, 2))->toBe(30000.0)
        ->and(round((float) $october->grossSalary, 2))->toBe(36000.0)
        ->and($october->salarySegments)->toHaveCount(1)
        ->and($october->salarySegments[0]['from'])->toBe('2026-10-01');

    // A month that straddles the change is weighted day by day.
    $straddling = app(\Modules\PAYROLL\Services\HRM\HrmEmployeePayrollDataProvider::class)
        ->employeesForPayroll('2026-09-21', '2026-10-20')->firstWhere(fn ($e) => $e->employeeId === 'INC-001');

    expect($straddling->salarySegments)->toHaveCount(2)
        ->and($straddling->salarySegments[0]['days'])->toBe(10)
        ->and($straddling->salarySegments[1]['days'])->toBe(20);
});

test('a staff advance is requested, approved, deducted from the payslip and closes itself when paid', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-10-31 18:00:00'));

    $actor = lifecycleBoot();
    $this->actingAs($actor);

    lifecycleBank();
    lifecycleFiscalYear(2026);

    $this->post(route('hrm.employees.store'), [
        'employee_id' => 'LOAN-001',
        'first_name' => 'Borrowing',
        'last_name' => 'Wardboy',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'gender' => 'Male',
        'joining_date' => '2024-01-01',
        'policy' => ['shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true, 'duty_hours' => 8, 'fallback_off_day' => 5],
        'salary' => ['basic_salary' => 15000, 'gross_salary' => 25000, 'net_salary' => 25000, 'effective_from' => '2024-01-01', 'is_current' => true, 'payment_method' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'bank_account_no' => '1931030788700'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT', 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30, 'missing_attendance_policy' => 'waive',
        'loan_approval_steps_text' => 'Payroll=Super Admin', 'cutlist_review_required' => false,
    ])->assertSessionHasNoErrors();

    // 3,000 over three months, first repayment due in October.
    $this->post(route('payroll.loans.store'), [
        'employee_id' => 'LOAN-001',
        'principal_amount' => 3000,
        'installment_count' => 3,
        'first_due_date' => '2026-10-01',
        'purpose' => 'His daughter\'s admission fee at the government school.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $loan = \Modules\PAYROLL\Models\PayrollLoan::query()->where('employee_id', 'LOAN-001')->firstOrFail();
    expect($loan->status)->toBe('pending')
        ->and((float) $loan->installment_amount)->toBe(1000.0)
        ->and((float) $loan->interest_rate)->toBe(0.0);

    // The requester cannot approve his own advance.
    $this->from(route('payroll.loans.show', $loan))->post(route('payroll.loans.approve', $loan), [])
        ->assertSessionHasErrors();
    expect(\Modules\PAYROLL\Models\PayrollLoan::query()->findOrFail($loan->id)->status)->toBe('pending');

    $this->actingAs(lifecycleApprover())->post(route('payroll.loans.approve', $loan), [
        'comments' => 'Approved by the payroll officer; three equal deductions from October.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($actor);
    $loan = \Modules\PAYROLL\Models\PayrollLoan::query()->findOrFail($loan->id);
    expect($loan->status)->toBe('active')
        ->and($loan->installments()->count())->toBe(3);

    // The repayment for October is allocated to October — before the sheet exists, which is the
    // order the pay desk has to work in.
    $this->post(route('payroll.loans.allocate-monthly'), [
        'pay_year' => 2026,
        'pay_month' => 10,
        'scope' => 'single',
        'employee_id' => 'LOAN-001',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Bus::fake();
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 10, 'include_punishments' => true]);

    $sheet = PayrollSalarySheet::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

    $slip = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', 'LOAN-001')->firstOrFail();
    $b = $slip->breakdown_json;

    expect($b['loan_installments'])->toHaveCount(1)
        ->and((float) $b['loan_installments'][0]['amount'])->toBe(1000.0)
        // 25,000 gross less the 1,000 advance — no attendance deductions, because he was never
        // rostered in this test and therefore never marked absent.
        ->and(round((float) $slip->gross_salary, 2))->toBe(25000.0)
        ->and(round((float) $slip->net_salary, 2))->toBe(24000.0);

    $this->post(route('payroll.sheets.release', $sheet))->assertRedirect();

    // Paying the slip marks the installment paid; the loan stays active because two more are due.
    $this->post(route('payroll.slips.payment-status', $slip), ['payment_status' => 'paid'])->assertRedirect();

    expect(\Modules\PAYROLL\Models\PayrollLoan::query()->findOrFail($loan->id)->status)->toBe('active')
        ->and(\Modules\PAYROLL\Models\PayrollLoanInstallment::query()->where('payroll_loan_id', $loan->id)->where('status', 'paid')->count())->toBe(1);
});

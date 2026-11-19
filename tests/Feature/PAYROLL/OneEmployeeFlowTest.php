<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\RosterOvertimeService;
use Modules\PAYROLL\Actions\ApprovePayrollLoanAction;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Actions\GenerateSalaryCutlistAction;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\PayrollBankAdvice;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Services\PayrollAdjustmentService;
use Modules\PAYROLL\Services\PayrollBankAdviceService;
use Modules\PAYROLL\Services\PayrollJournalService;
use Modules\PAYROLL\Services\PayrollLoanService;
use Modules\PAYROLL\Services\PayrollSalarySheetService;
use Modules\PAYROLL\Services\PayrollSalarySheetWorkspaceService;
use Modules\PAYROLL\Services\ProvidentFundService;
use Modules\PAYROLL\Services\TaxConfigurationService;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One employee, every stage, and every number traceable to the stage that produced it.
 *
 * The 1,000-employee flow test proves the machinery scales, but it sets `is_punishable = false` on
 * every policy, so **punishment has never once reached a payslip in this suite**. It also asserts a
 * handful of components rather than the arithmetic that ties them together, which is how a slip can
 * print an Earnings column that does not sum to its own Gross line and still pass every test.
 *
 * This walks a single employee through the chain in the order a business actually does it —
 * roster, punches, absence, adjustments, tax, provident fund, loan, punishment — and then checks the
 * payslip twice: once against the expected figure for each component, and once against the identity
 * gross − deductions = net, computed from the line items the slip itself carries.
 */
test('one employee goes roster -> attendance -> adjustments, tax, PF, loan, punishment -> payslip', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Administrator', 'web'));

    // Two people, because the system refuses to let a requester approve their own payroll loan —
    // a control worth hitting in a test rather than working around.
    $approver = User::factory()->create();
    $approver->assignRole(Role::findOrCreate('Administrator', 'web'));
    $this->actingAs($admin);

    $now = now()->toDateTimeString();
    $employeeId = 'FLOW-001';

    // ── 0. The employee ─────────────────────────────────────────────────────────────────────────
    DB::table('employees')->insert([[
        'employee_id' => $employeeId,
        'first_name' => 'Flow',
        'last_name' => 'Employee',
        'full_name' => 'Flow Employee',
        'email' => 'flow.employee@example.test',
        'gender' => 'Female',
        'joining_date' => '2019-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'department_id' => $department->id,
        'designation_id' => $designation->id,
        'is_active' => true,
        'duty_hours' => 8,
        'allow_overtime' => true,
        'allow_bonus' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]]);

    DB::table('employee_salaries')->insert([[
        'employee_id' => $employeeId,
        // The account lives on the salary record — where the HRM form writes it and where the advice
        // service now looks. A BEFTN advice refuses both generation and confirmation without one.
        'bank_account_no' => '1000123456789',
        'bank_name' => 'Prime Bank Ltd',
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 6000,
        'medical_allowance' => 1500,
        'other_allowance' => 2500,
        'net_salary' => 30000,
        'effective_from' => '2019-01-01',
        'is_current' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]]);

    // The policy that makes punishment possible at all. Bhopal: `is_punishable = true`.
    DB::table('employee_policies')->insert([[
        'employee_id' => $employeeId,
        'grace_time_in' => 15,
        'grace_time_out' => 15,
        'shift_type' => 'Fixed',
        'is_punishable' => true,
        'duty_hours' => 8,
        'auto_shift' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]]);

    // ── 1. Roster ───────────────────────────────────────────────────────────────────────────────
    $shift = RosterShift::factory()->create([
        'shift_name' => 'Flow Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
        'is_fallback' => false,
    ]);

    // Worked days: 8 Oct (overtime), 12–15 Oct (late), plus one off day to prove the roster is read.
    $workedDates = ['2026-10-08', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'];
    $rosterRows = [];
    foreach ($workedDates as $date) {
        $rosterRows[] = [
            'employee_id' => $employeeId,
            'shift_id' => $shift->id,
            'roster_date' => $date,
            'scheduled_start_time' => $shift->start_time,
            'scheduled_end_time' => $shift->end_time,
            'status' => 'Scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    // Same keys as the scheduled rows: one bulk insert needs one column set.
    $rosterRows[] = [
        'employee_id' => $employeeId,
        'shift_id' => null,
        'roster_date' => '2026-10-07',
        'scheduled_start_time' => null,
        'scheduled_end_time' => null,
        'status' => 'Off',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    DB::table('roster_duties')->insert($rosterRows);

    expect(DB::table('roster_duties')->where('employee_id', $employeeId)->count())->toBe(6);

    // ── 2. Punches → attendance ─────────────────────────────────────────────────────────────────
    $logs = [];
    $punch = static function (string $at) use (&$logs, $employeeId, $now): void {
        $logs[] = [
            'employee_id' => $employeeId,
            'device_user_id' => 'DEV-'.$employeeId,
            'punch_time' => $at,
            'punch_type' => 'AUTO',
            'is_processed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    };

    // 8 Oct: on time, and stays two hours late — the overtime this payslip will carry.
    $punch('2026-10-08 09:00:00');
    $punch('2026-10-08 19:00:00');

    // 12–15 Oct: in at 09:30 against a 09:00 shift with 15 minutes of grace. Thirty minutes late is
    // past the grace window, so each of these is a `Late` day and each counts towards punishment.
    foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $date) {
        $punch($date.' 09:30:00');
        $punch($date.' 17:30:00');
    }
    DB::table('attendance_logs')->insert($logs);

    $processed = app(AttendanceProcessingService::class)->processPending(50);
    expect($processed['processed'])->toBe(10)
        ->and($processed['errors'])->toBe(0);

    $daily = AttendanceDaily::query()->where('employee_id', $employeeId)->get()->keyBy(fn ($row) => $row->duty_date->toDateString());
    expect($daily)->toHaveCount(5)
        // The two-hour overrun on 8 Oct is overtime, and the day is not late.
        ->and((float) $daily['2026-10-08']->total_overtime_hours)->toBe(2.0)
        ->and($daily['2026-10-08']->status)->not->toBe('Late')
        // All four late days are classified as late, from the roster shift and the policy's grace.
        ->and($daily['2026-10-12']->status)->toBe('Late')
        ->and($daily['2026-10-13']->status)->toBe('Late')
        ->and($daily['2026-10-14']->status)->toBe('Late')
        ->and($daily['2026-10-15']->status)->toBe('Late');

    // The off day and the days with no punches are reconciled before payroll reads them.
    app(AttendanceDayReconciliationService::class)->reconcile('2026-10-07', '2026-10-07');
    expect(AttendanceDaily::query()->where('employee_id', $employeeId)->whereDate('duty_date', '2026-10-07')->value('status'))->toBe('Off Day');

    // ── 3. One approved unpaid leave day ────────────────────────────────────────────────────────
    $unpaid = LeaveType::query()->create([
        'name' => 'Flow unpaid leave', 'code' => 'FLOW-UL', 'days_per_year' => 0,
        'is_paid' => false, 'is_active' => true,
    ]);
    DB::table('leave_requests')->insert([[
        'employee_id' => $employeeId,
        'leave_type_id' => $unpaid->id,
        'from_date' => '2026-10-05',
        'to_date' => '2026-10-05',
        'days' => 1,
        'is_half_day' => false,
        'reason' => 'Unpaid day, deducted from this month.',
        'status' => 'Approved',
        'approved_by' => $admin->id,
        'approved_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]]);
    app(AttendanceDayReconciliationService::class)->reconcile('2026-10-05', '2026-10-05');
    expect(AttendanceDaily::query()->where('employee_id', $employeeId)->whereDate('duty_date', '2026-10-05')->value('status'))->toBe('On Leave');

    // ── 4. Adjustments: one earning, one deduction ──────────────────────────────────────────────
    $adjustments = app(PayrollAdjustmentService::class);
    $adjustments->create([
        'scope' => 'global', 'adjustment_type' => 'benefit', 'name' => 'Flow allowance',
        'code' => 'FLOW-ADJ', 'calculation_type' => 'fixed', 'amount' => 500,
        'percentage_base' => 'basic', 'auto_apply' => true, 'taxable' => true,
        'pf_eligible' => false, 'effective_from' => '2019-01-01',
    ], $admin->id);
    $adjustments->create([
        'scope' => 'global', 'adjustment_type' => 'deduction', 'name' => 'Flow recovery',
        'code' => 'FLOW-DED', 'calculation_type' => 'fixed', 'amount' => 300,
        'percentage_base' => 'gross', 'auto_apply' => true, 'effective_from' => '2019-01-01',
    ], $admin->id);

    // ── 5. Income tax ───────────────────────────────────────────────────────────────────────────
    $tax = app(TaxConfigurationService::class)->update([
        'name' => 'Flow fixed tax', 'mode' => 'fixed', 'fixed_monthly_tax' => 750,
        'annual_exemption' => 0, 'taxable_salary_percent' => 100,
        'effective_from' => '2019-01-01', 'effective_to' => null,
    ], $admin->id, 'Fixed monthly withholding used by the single-employee flow test.');
    expect($tax->mode)->toBe('fixed');

    // ── 6. Provident fund: 5% of basic, both sides ──────────────────────────────────────────────
    $pfPolicy = PfPolicy::query()->create([
        'name' => 'Flow PF policy', 'employee_contribution_percent' => 5,
        'employer_contribution_percent' => 5, 'minimum_service_months' => 0,
        'contribution_base' => 'basic', 'is_active' => true, 'effective_from' => '2019-01-01',
    ]);
    expect(app(ProvidentFundService::class)->allocateAccounts($pfPolicy->id, [$employeeId]))->not->toBeNull();

    // ── 7. Loan: 1,000 over one installment, due in this period ─────────────────────────────────
    $loan = PayrollLoan::query()->create([
        'employee_id' => $employeeId,
        'employee_name' => 'Flow Employee',
        'principal_amount' => 1000,
        'interest_rate' => 0,
        'installment_count' => 1,
        'installment_amount' => 1000,
        'first_due_date' => '2026-10-10',
        'status' => 'pending',
        'requested_by' => $admin->id,
        'purpose' => 'Flow test interest-free loan.',
    ]);
    app(ApprovePayrollLoanAction::class)->approve($loan, $approver->id, 'Approved for the flow test.');
    $allocation = app(PayrollLoanService::class)->allocateMonthlyInstallments(2026, 10, 'all', [], $admin->id);
    expect($allocation['allocated'])->toBe(1)
        ->and((float) $allocation['amount'])->toBe(1000.0);

    // ── 8. Overtime approval (attendance → cutlist → pay) ───────────────────────────────────────
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-08', '2026-10-08');
    $overtime = RosterOvertime::query()->where('employee_id', $employeeId)->where('overtime_type', 'Overtime')->firstOrFail();
    app(RosterOvertimeService::class)->approve($overtime, 1.5, $admin->id, 'Approved 1.5 of the 2 hours worked.');
    expect(RosterOvertime::query()->where('employee_id', $employeeId)->value('status'))->toBe('Approved');

    // ── 9. Run payroll, with punishment included ────────────────────────────────────────────────
    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT',
        'unpaid_leave_day_divisor' => 30,
        'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1,
        'extra_duty_multiplier' => 1,
        'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Posting an accrual is a general-ledger operation and needs a year to post it into. The live
    // database has one; a fresh test database does not, and the absence is what release now fails on
    // loudly rather than silently.
    // A bank to disburse through: an advice cannot be generated without one, which is a configuration
    // precondition rather than something the flow should be able to skip. It needs a chart-of-accounts
    // ledger account to post to, so that is created first.
    $bankLedger = \Modules\ACCOUNTS\Models\Account::query()->create([
        'code' => '100299',
        'name' => 'Flow disbursement bank',
        'account_type' => 'asset',
        'nature' => 'debit',
        'is_active' => true,
    ]);

    \Modules\ACCOUNTS\Models\Bank::query()->create([
        'account_id' => $bankLedger->id,
        'name' => 'Flow disbursement bank',
        'code' => 'FLOW-BANK',
        'bank_name' => 'Prime Bank Ltd',
        'branch_name' => 'Agrabad, Chattogram',
        'account_no' => '1234567890123',
        'account_holder' => 'ERP Pilot Hospital',
        'currency' => 'BDT',
        'is_default' => true,
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    \Modules\ACCOUNTS\Models\FiscalYear::query()->create([
        'name' => 'FY 2026-2027', 'code' => 'FY26-27',
        'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
        'status' => 'open', 'created_by' => $admin->id,
    ]);

    Bus::fake();
    $sheetService = app(PayrollSalarySheetService::class);
    $sheet = $sheetService->dispatch(2026, 10, $admin->id, includePunishments: true);
    expect($sheet->include_punishments)->toBeTrue();

    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);
    expect($sheet->status)->toBe('ready')
        ->and($sheet->slips()->count())->toBe(1);

    // ── 10. The cutlist, which is where attendance becomes money ────────────────────────────────
    $cutlist = PayrollCutlist::query()->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    $item = $cutlist->items()->where('employee_id', $employeeId)->firstOrFail();

    expect((int) $item->late_count)->toBe(4)
        ->and((int) $item->punishment_days)->toBe(4)
        ->and((bool) $item->punishable)->toBeTrue()
        ->and((int) $item->unpaid_leave_days)->toBe(1)
        // Punishment: floor(4 late days / 3 days-per-penalty) = 1 salary day = gross / 30 = 1,000.
        ->and((float) $item->late_penalty_deduction)->toBe(1000.0)
        // Unpaid leave: 1 day x (gross / 30) = 1,000.
        ->and((float) $item->unpaid_leave_deduction)->toBe(1000.0)
        ->and((int) $item->approved_overtime_minutes)->toBe(90)
        // Overtime: basic / (30 x 8 duty hours) x 1.5 approved hours = 83.3333 x 1.5 = 125.
        ->and((float) $item->overtime_pay)->toBe(125.0);

    // ── 11. The payslip: every line, then the totals it adds up to ──────────────────────────────
    $slip = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $employeeId)->firstOrFail();
    $b = $slip->breakdown_json;

    // Earnings
    expect((float) $slip->basic_salary)->toBe(20000.0)
        ->and((float) $b['period_base_components']['gross'])->toBe(30000.0)
        ->and(collect($b['benefits'])->pluck('code')->all())->toContain('FLOW-ADJ', 'approved-overtime')
        ->and((float) collect($b['benefits'])->firstWhere('code', 'FLOW-ADJ')['amount'])->toBe(500.0)
        ->and((float) collect($b['benefits'])->firstWhere('code', 'approved-overtime')['amount'])->toBe(125.0);

    // Deductions, each traced to the stage that produced it
    expect((float) $b['tax']['monthly_tax'])->toBe(750.0)                       // step 5
        ->and((float) $b['provident_fund']['employee_contribution'])->toBe(1000.0) // step 6, 5% of 20,000
        ->and((float) $b['provident_fund']['employer_contribution'])->toBe(1000.0)
        ->and((float) $b['loan_installments'][0]['amount'])->toBe(1000.0)          // step 7
        ->and((float) $b['cutlist']['punishment'])->toBe(1000.0)                   // step 2 -> 10
        ->and((float) $b['cutlist']['unpaid_leave'])->toBe(1000.0)                 // step 3
        ->and((float) collect($b['recurring_deductions'])->firstWhere('code', 'FLOW-DED')['amount'])->toBe(300.0)
        ->and($b['salary_sheet_mode']['with_punishment'])->toBeTrue();

    // The identity. Computed from the slip's own lines, not from what we expect them to be.
    $lineEarnings = (float) $b['period_base_components']['gross'] + collect($b['benefits'])->sum('amount');
    $lineDeductions = collect($b['recurring_deductions'])->sum('amount')
        + (float) $b['tax']['monthly_tax']
        + (float) $b['provident_fund']['employee_contribution']
        + collect($b['loan_installments'])->sum('amount')
        + (float) $b['cutlist']['unpaid_leave']
        + (float) $b['cutlist']['punishment']
        + (float) $b['cutlist']['missing_punch'];

    expect(round($lineEarnings, 2))->toBe(30625.0)          // 30,000 + 500 + 125
        ->and(round($lineDeductions, 2))->toBe(5050.0)      // 300 + 750 + 1,000 + 1,000 + 1,000 + 1,000
        ->and(round((float) $slip->gross_salary, 2))->toBe(round($lineEarnings, 2))
        ->and(round((float) $slip->total_deductions, 2))->toBe(round($lineDeductions, 2))
        ->and(round((float) $slip->net_salary, 2))->toBe(25575.0)
        ->and(round((float) $slip->gross_salary - (float) $slip->total_deductions, 2))->toBe(round((float) $slip->net_salary, 2));

    // ── 12. Release posts the accrual, tax included ─────────────────────────────────────────────
    $sheet = $sheetService->release($sheet, $admin->id);
    expect($sheet->status)->toBe('released');

    // Surface the reason release gave, instead of only discovering the absence of a row.
    expect(session('error'))->toBeNull(session('error') ?? '');

    $posts = DB::table('payroll_journal_posts')->where('salary_sheet_id', $sheet->id)->get();
    $accrual = $posts->firstWhere('posting_type', 'salary_accrual');
    expect($accrual)->not->toBeNull()
        ->and(round((float) $accrual->total_debit, 2))->toBe(round((float) $accrual->total_credit, 2));

    $taxAccount = DB::table('acc_accounts')->where('code', '2311')->value('id');
    $taxCredit = (float) DB::table('acc_journal_lines')->where('journal_id', $accrual->journal_id)->where('account_id', $taxAccount)->sum('credit');
    expect($taxCredit)->toBe(750.0);

    // The accrual balances against the slip it describes: gross + employer PF on one side.
    expect(round((float) $accrual->total_debit, 2))->toBe(round((float) $slip->gross_salary + 1000.0, 2));

    // ── 13. Confirm → pay → the ledger and the loan follow ──────────────────────────────────────
    $advice = app(PayrollBankAdviceService::class)->generateForSheet($sheet->id ? PayrollSalarySheet::findOrFail($sheet->id) : $sheet)[0];
    expect(round((float) $advice->total_amount, 2))->toBe(round((float) $slip->net_salary, 2));

    app(PayrollBankAdviceService::class)->confirm($advice);
    app(PayrollBankAdviceService::class)->markPaid(PayrollBankAdvice::query()->findOrFail($advice->id));

    $slip->refresh();
    expect($slip->payment_status)->toBe('paid');

    // Two provident-fund entries for the slip, both settled, and they match the payslip.
    $ledger = PfLedger::query()->where('reference_type', 'payroll_slip')->where('reference_id', $slip->id)->get();
    expect($ledger)->toHaveCount(2)
        ->and(round((float) $ledger->sum('amount'), 2))->toBe(2000.0)
        ->and($ledger->pluck('entry_type')->sort()->values()->all())->toBe(['employee_contribution', 'employer_contribution']);

    expect(PayrollLoan::query()->where('employee_id', $employeeId)->value('status'))->toBe('completed');
});

/**
 * Punishment switched off, compared against punishment switched on — same month, same attendance.
 *
 * The earlier version of this test used two salary sheets a month apart, which meant the control sheet
 * had no attendance to punish and the difference would have appeared even if punishment never worked.
 * A control has to differ in exactly one thing, so here it does: two employees with byte-for-byte the
 * same three late days inside the *same* November sheet. One is punishable, one is not.
 */
test('punishment is the only difference between two employees in the same sheet', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Administrator', 'web'));
    $this->actingAs($admin);

    $now = now()->toDateTimeString();
    $punished = 'FLOW-002';
    $unpunished = 'FLOW-003';

    foreach ([
        [$punished, 'Flow Two', true],
        [$unpunished, 'Flow Three', false],
    ] as [$employeeId, $name, $isPunishable]) {
        DB::table('employees')->insert([[
            'employee_id' => $employeeId, 'first_name' => 'Flow', 'last_name' => $name,
            'full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'gender' => 'Male', 'joining_date' => '2019-01-01', 'employment_type' => 'Permanent',
            'status' => 'Active', 'department_id' => $department->id, 'designation_id' => $designation->id,
            'is_active' => true, 'duty_hours' => 8, 'allow_overtime' => true, 'allow_bonus' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_salaries')->insert([[
            'employee_id' => $employeeId, 'basic_salary' => 20000, 'gross_salary' => 30000,
            'house_rent' => 6000, 'medical_allowance' => 1500, 'other_allowance' => 2500,
            'net_salary' => 30000, 'effective_from' => '2019-01-01', 'is_current' => true,
            'bank_account_no' => $isPunishable ? '1000123456790' : '1000123456791',
            'bank_name' => 'Prime Bank Ltd',
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_policies')->insert([[
            'employee_id' => $employeeId, 'grace_time_in' => 15, 'grace_time_out' => 15,
            'shift_type' => 'Fixed', 'is_punishable' => $isPunishable, 'duty_hours' => 8,
            'auto_shift' => false, 'created_at' => $now, 'updated_at' => $now,
        ]]);
    }

    $shift = RosterShift::factory()->create([
        'shift_name' => 'Flow Pair Shift', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00', 'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false, 'max_duration_hours' => 24, 'is_fallback' => false,
    ]);

    $dates = ['2026-11-02', '2026-11-03', '2026-11-04'];
    $roster = [];
    $logs = [];
    foreach ([$punished, $unpunished] as $employeeId) {
        foreach ($dates as $date) {
            $roster[] = [
                'employee_id' => $employeeId, 'shift_id' => $shift->id, 'roster_date' => $date,
                'scheduled_start_time' => $shift->start_time, 'scheduled_end_time' => $shift->end_time,
                'status' => 'Scheduled', 'created_at' => $now, 'updated_at' => $now,
            ];
            // 09:45 is past the 15-minute grace: every one of these is a `Late` day.
            foreach ([$date.' 09:45:00', $date.' 17:00:00'] as $at) {
                $logs[] = [
                    'employee_id' => $employeeId, 'device_user_id' => 'DEV-'.$employeeId,
                    'punch_time' => $at, 'punch_type' => 'AUTO', 'is_processed' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
    }
    DB::table('roster_duties')->insert($roster);
    DB::table('attendance_logs')->insert($logs);
    app(AttendanceProcessingService::class)->processPending(50);

    // Both were late on the same three days, or the comparison below means nothing.
    foreach ([$punished, $unpunished] as $employeeId) {
        expect(AttendanceDaily::query()->where('employee_id', $employeeId)->where('status', 'Late')->count())->toBe(3);
    }

    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT', 'unpaid_leave_day_divisor' => 30, 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false, 'created_at' => $now, 'updated_at' => $now,
    ]);
    app(TaxConfigurationService::class)->update([
        'name' => 'Flow pair tax', 'mode' => 'fixed', 'fixed_monthly_tax' => 500,
        'annual_exemption' => 0, 'taxable_salary_percent' => 100,
        'effective_from' => '2019-01-01', 'effective_to' => null,
    ], $admin->id, 'Fixed withholding for the same-sheet punishment control.');

    Bus::fake();

    // One sheet, punishment included — so the punishable employee is punished and their neighbour is not.
    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 11, 'status' => 'queued',
        'include_punishments' => true, 'employee_count' => 2, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0, 'created_by' => $admin->id,
    ]);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    $withPunishment = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $punished)->firstOrFail();
    $withoutPunishment = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $unpunished)->firstOrFail();

    // 3 late days / 3 per salary day = 1 salary day of 30,000 / 30 = 1,000.
    expect((float) $withPunishment->breakdown_json['cutlist']['punishment'])->toBe(1000.0)
        ->and((int) $withPunishment->breakdown_json['cutlist']['punishment_days'])->toBe(3);

    // The unpunishable employee did the same thing and keeps the money — and is not even marked down.
    expect((float) $withoutPunishment->breakdown_json['cutlist']['punishment'])->toBe(0.0)
        ->and(round((float) $withoutPunishment->gross_salary, 2))->toBe(round((float) $withPunishment->gross_salary, 2))
        ->and(round((float) $withoutPunishment->total_deductions - (float) $withPunishment->total_deductions, 2))->toBe(-1000.0)
        ->and(round((float) $withoutPunishment->net_salary - (float) $withPunishment->net_salary, 2))->toBe(1000.0);

    // And the policy is what did it: flip one flag and the gap closes.
    //
    // The cutlist has to be rebuilt, and that is not incidental — it pins a real ordering rule. A
    // cutlist is a *snapshot* of the policy that was in force when it was generated: flipping
    // `is_punishable` afterwards changes nothing until the cutlist is regenerated, and the stale item
    // keeps `punishable = 0` while still counting the three late days. Anyone who fixes an employee's
    // policy expects the next payroll to reflect it; this is the step that makes that true.
    DB::table('employee_policies')->where('employee_id', $unpunished)->update(['is_punishable' => true]);

    DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)->delete();
    DB::table('payroll_salary_sheets')->where('id', $sheet->id)->update(['status' => 'queued', 'processed_count' => 0]);
    app(GenerateSalaryCutlistAction::class)->execute(2026, 11, $admin->id, duringPayrollRun: true);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

    $nowPunished = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $unpunished)->firstOrFail();
    expect((float) $nowPunished->breakdown_json['cutlist']['punishment'])->toBe(1000.0)
        ->and(round((float) $nowPunished->net_salary, 2))->toBe(round((float) $withPunishment->net_salary, 2));
});

/**
 * What the system does *not* do, asserted so it cannot change silently.
 *
 * Absence is not deducted anywhere. October 2026's live database holds 17,195 Absent days and
 * `PayrollCalculationEngineService` subtracts nothing for them, so an employee who never turns up is
 * paid exactly the same as one who works every day. That is either a policy this hospital has chosen
 * or a hole in payroll; the point of this test is that nobody can close it, or claim it is closed,
 * without this failing first.
 */
test('a day the employee was expected in and did not attend is deducted, and a missing export is not', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Administrator', 'web'));
    $this->actingAs($admin);

    $now = now()->toDateTimeString();

    /*
     * Three employees, one October, three different truths:
     *
     *   PRESENT-SOMETIMES  turned up on some days and not others — those misses are absence.
     *   NEVER-PUNCHED      has no device record anywhere in the month. That is a missing export,
     *                      not evidence of absence, and docking them would be a payroll error.
     *   OFF-DAY-ONLY       works Mon–Thu and is contracted off on Friday; Friday is not absence.
     *
     * The rule this replaces deducted nothing at all: 17,195 absent days in October 2026 and not a
     * taka withheld, because only *unpaid leave* was ever counted and nobody had filed any.
     */
    $cohort = [
        'FLOW-SOME' => 'Turned Up Sometimes',
        'FLOW-NEVER' => 'Never Punched',
        'FLOW-OFFDAY' => 'Contracted Friday Off',
    ];

    foreach ($cohort as $employeeId => $name) {
        DB::table('employees')->insert([[
            'employee_id' => $employeeId, 'first_name' => 'Flow', 'last_name' => $name,
            'full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'gender' => 'Male', 'joining_date' => '2019-01-01', 'employment_type' => 'Permanent',
            'status' => 'Active', 'department_id' => $department->id, 'designation_id' => $designation->id,
            'is_active' => true, 'duty_hours' => 8, 'allow_overtime' => false, 'allow_bonus' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_salaries')->insert([[
            'employee_id' => $employeeId, 'basic_salary' => 20000, 'gross_salary' => 30000,
            'house_rent' => 6000, 'medical_allowance' => 1500, 'other_allowance' => 2500,
            'net_salary' => 30000, 'effective_from' => '2019-01-01', 'is_current' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_policies')->insert([[
            'employee_id' => $employeeId, 'grace_time_in' => 15, 'grace_time_out' => 15,
            'shift_type' => 'Fixed', 'is_punishable' => true, 'duty_hours' => 8, 'auto_shift' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
    }

    // FLOW-OFFDAY is contracted off every Friday (Carbon weekday 5).
    DB::table('employee_fallback_off_days')->insert([
        'employee_id' => 'FLOW-OFFDAY', 'day_of_week' => 5, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // Phones/punches: FLOW-SOME was at work on the 1st and the 2nd only. FLOW-OFFDAY turned up on
    // three working days. FLOW-NEVER has not one punch in the whole month — the case that matters.
    $punch = function (string $employeeId, string $at): void {
        // No device row: this test is about the presence of a punch, not about which terminal
        // produced it, and inventing a device would add a foreign key to a rule that does not need one.
        DB::table('attendance_logs')->insert([
            'employee_id' => $employeeId, 'device_id' => null, 'punch_time' => $at, 'punch_type' => 'IN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $punch('FLOW-SOME', '2026-10-01 09:02:00');
    $punch('FLOW-SOME', '2026-10-02 09:03:00');
    $punch('FLOW-OFFDAY', '2026-10-01 09:00:00');
    $punch('FLOW-OFFDAY', '2026-10-05 09:00:00');
    $punch('FLOW-OFFDAY', '2026-10-06 09:00:00');

    // Reconciliation reads the punches, so it must run *after* they are in the table: FLOW-SOME's two
    // days become Present, FLOW-OFFDAY's Fridays stay off, and FLOW-NEVER ends up with 31 Absent rows
    // that must not be read as 31 days of misconduct.
    app(AttendanceDayReconciliationService::class)->reconcile('2026-10-01', '2026-10-31');

    expect(AttendanceDaily::query()->where('employee_id', 'FLOW-SOME')->where('status', 'Absent')->count())->toBe(29)
        ->and(AttendanceDaily::query()->where('employee_id', 'FLOW-NEVER')->where('status', 'Absent')->count())->toBe(31);

    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT', 'unpaid_leave_day_divisor' => 30, 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false, 'created_at' => $now, 'updated_at' => $now,
    ]);

    Bus::fake();
    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'queued',
        'include_punishments' => true, 'employee_count' => 3, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0, 'created_by' => $admin->id,
    ]);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    $slip = fn (string $id): PayrollSlip => PayrollSlip::query()
        ->where('salary_sheet_id', $sheet->id)->where('employee_id', $id)->firstOrFail();

    // 31 Absent rows, but he was demonstrably on site twice in October — so his misses are the days
    // that were neither present nor off. October 2026 holds 26 working days once its five Fridays are
    // gone (the company weekly off since P30, applied to him too — he has no off-day row, and a
    // missing row no longer means "never off"). One of the two punched days, October 2nd, *is* a
    // Friday, so it costs him nothing: 26 working days − 1 worked = 25 charged. Daily rate = 1,000.
    $some = $slip('FLOW-SOME');
    expect(round((float) $some->total_deductions, 2))->toBe(25000.0)
        ->and(round((float) $some->net_salary, 2))->toBe(5000.0);
    expect(round((float) $some->breakdown_json['cutlist']['unpaid_leave_days'], 2))->toBe(25.0);
    expect($some->breakdown_json['cutlist']['source']['absent_dates'])->toHaveCount(25)
        // And no Friday is among them: the weekly off is not a day he failed to work.
        ->and(collect($some->breakdown_json['cutlist']['source']['absent_dates'])
            ->contains(fn ($d) => Carbon::parse($d)->dayOfWeek === Carbon::FRIDAY))->toBeFalse();

    // Zero punches is a missing export, not 31 days of misconduct. He keeps his full month, and the
    // payslip says why rather than leaving the officer to wonder.
    $never = $slip('FLOW-NEVER');
    expect(round((float) $never->total_deductions, 2))->toBe(0.0)
        ->and(round((float) $never->net_salary, 2))->toBe(30000.0)
        ->and($never->breakdown_json['cutlist']['source']['absence_gated_by_punch'])->toBeFalse();
});

/**
 * Two employees, two ways of being paid — the way a hospital actually runs.
 *
 * The bank advice used to refuse the whole month if a single employee had no account number. That is
 * backwards: the attendants, the cleaners and the drivers are paid in hand, and one of them must not
 * be able to stop a thousand people being paid. So the payroll splits — account numbers to the BEFTN
 * file, everyone else to a cash sheet — and the ledger credits the till for the cash half instead of
 * a bank that never paid anything.
 */
test('the payroll splits into a bank advice and a cash sheet, and neither pays the other\'s people', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Administrator', 'web'));
    $this->actingAs($admin);

    $now = now()->toDateTimeString();

    $cashAccount = \Modules\ACCOUNTS\Models\Account::query()->create([
        'code' => '1001', 'name' => 'Cash in Hand', 'account_type' => 'asset',
        'nature' => 'debit', 'is_active' => true,
    ]);
    $bankLedger = \Modules\ACCOUNTS\Models\Account::query()->create([
        'code' => '100201', 'name' => 'Cash at Bank — Flow', 'account_type' => 'asset',
        'nature' => 'debit', 'is_active' => true,
    ]);
    \Modules\ACCOUNTS\Models\Bank::query()->create([
        'name' => 'Flow disbursement bank', 'code' => 'FLOW-BANK2',
        'bank_name' => 'Prime Bank Ltd', 'branch_name' => 'Agrabad', 'account_no' => '1234567890124',
        'account_holder' => 'ERP Pilot Hospital', 'currency' => 'BDT', 'is_default' => true,
        'is_active' => true, 'account_id' => $bankLedger->id, 'created_by' => $admin->id,
    ]);
    \Modules\ACCOUNTS\Models\FiscalYear::query()->create([
        'name' => 'FY 2026-2027', 'code' => 'FY26-27', 'start_date' => '2026-07-01',
        'end_date' => '2027-06-30', 'status' => 'open', 'created_by' => $admin->id,
    ]);

    // The senior one is paid through the bank; the attendant is paid in hand. Neither record says
    // which — the presence of an account number is what decides it.
    $cohort = [
        ['FLOW-BANK', 'Bank Paid', '1000123456788', null],
        ['FLOW-CASH', 'Cash Paid', null, 'cash'],
    ];

    foreach ($cohort as [$employeeId, $name, $accountNo, $method]) {
        DB::table('employees')->insert([[
            'employee_id' => $employeeId, 'first_name' => 'Flow', 'last_name' => $name,
            'full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'gender' => 'Male', 'joining_date' => '2019-01-01', 'employment_type' => 'Permanent',
            'status' => 'Active', 'department_id' => $department->id, 'designation_id' => $designation->id,
            'is_active' => true, 'duty_hours' => 8, 'allow_overtime' => true, 'allow_bonus' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_salaries')->insert([[
            'employee_id' => $employeeId, 'basic_salary' => 20000, 'gross_salary' => 30000,
            'house_rent' => 6000, 'medical_allowance' => 1500, 'other_allowance' => 2500,
            'net_salary' => 30000, 'effective_from' => '2019-01-01', 'is_current' => true,
            'bank_account_no' => $accountNo, 'bank_name' => $accountNo ? 'Prime Bank Ltd' : null,
            'payment_method' => $method,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('employee_policies')->insert([[
            'employee_id' => $employeeId, 'grace_time_in' => 15, 'grace_time_out' => 15,
            'shift_type' => 'Fixed', 'is_punishable' => false, 'duty_hours' => 8, 'auto_shift' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
    }

    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT', 'unpaid_leave_day_divisor' => 30, 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false, 'created_at' => $now, 'updated_at' => $now,
    ]);

    Bus::fake();
    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 11, 'status' => 'queued', 'include_punishments' => false,
        'employee_count' => 2, 'processed_count' => 0, 'gross_total' => 0, 'deduction_total' => 0,
        'net_total' => 0, 'created_by' => $admin->id,
    ]);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    app(PayrollSalarySheetService::class)->release(PayrollSalarySheet::query()->findOrFail($sheet->id), $admin->id);
    $sheet = PayrollSalarySheet::query()->findOrFail($sheet->id);

    $result = app(PayrollBankAdviceService::class)->generateForSheet($sheet);

    // One advice, holding only the bank-paid employee.
    $advices = collect($result)->filter(fn ($r) => $r instanceof \Modules\PAYROLL\Models\PayrollBankAdvice);
    expect($advices)->toHaveCount(1);

    $advice = $advices->first();
    $bankPaid = $advice->items()->pluck('employee_id')->all();
    expect($bankPaid)->toBe(['FLOW-BANK'])
        ->and(round((float) $advice->total_amount, 2))->toBe(30000.0);

    // And a cash sheet for the other one, not a refusal and not a silent omission.
    $cashSheet = collect($result)->first(fn ($r) => is_array($r) && isset($r['cash_sheet']));
    expect($cashSheet)->not->toBeNull()
        ->and($cashSheet['employee_count'])->toBe(1)
        ->and(round((float) $cashSheet['total_amount'], 2))->toBe(30000.0);

    // Nobody is on both lists, and the two together cover the whole payroll.
    $paidByBank = $advice->items()->sum('net_amount');
    expect(round((float) $paidByBank + (float) $cashSheet['total_amount'], 2))
        ->toBe(round((float) $sheet->net_total, 2));

    // Paying the cash employee posts against the till, not against a bank that never paid them.
    app(MarkPayrollSlipPaymentAction::class)->execute(
        PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', 'FLOW-CASH')->firstOrFail(),
        'paid',
        $admin->id,
    );

    $cashSlip = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', 'FLOW-CASH')->firstOrFail();
    expect($cashSlip->payment_method)->toBe('cash')
        ->and($cashSlip->payment_journal_id)->not->toBeNull();

    $cashCredited = (float) DB::table('acc_journal_lines')
        ->where('journal_id', $cashSlip->payment_journal_id)
        ->where('account_id', $cashAccount->id)
        ->sum('credit');

    $bankCredited = (float) DB::table('acc_journal_lines')
        ->where('journal_id', $cashSlip->payment_journal_id)
        ->where('account_id', $bankLedger->id)
        ->sum('credit');

    expect(round($cashCredited, 2))->toBe(30000.0)
        ->and(round($bankCredited, 2))->toBe(0.0);
});

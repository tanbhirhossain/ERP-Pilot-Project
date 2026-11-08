<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\InformedRequestService;
use Modules\HRM\Services\RosterOvertimeService;
use Modules\PAYROLL\Actions\ApprovePayrollLoanAction;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PayrollTaxAudit;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Models\SalaryBenefitTemplate;
use Modules\PAYROLL\Models\SalaryDeductionTemplate;
use Modules\PAYROLL\Models\SalaryIncrement;
use Modules\PAYROLL\Services\PayrollAdjustmentService;
use Modules\PAYROLL\Services\PayrollBonusService;
use Modules\PAYROLL\Services\PayrollLoanService;
use Modules\PAYROLL\Services\PayrollSalarySheetService;
use Modules\PAYROLL\Services\PayrollSettlementService;
use Modules\PAYROLL\Services\SalaryIncrementService;
use Modules\PAYROLL\Services\TaxConfigurationService;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('full HRM-to-PAYROLL flow processes 1000 employees through attendance and payroll controls', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $insertRows = static function (string $table, array $rows): void {
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    };

    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole(Role::findOrCreate('Administrator', 'web'));
    $now = now()->toDateTimeString();
    $employeeCount = 1000;
    $leaverId = 'SCALE-1000';

    // Seed a full, deterministic test workforce efficiently; all business operations below
    // run through the real HRM/PAYROLL services and actions.
    $employeeRows = [];
    $ids = [];
    for ($number = 1; $number <= $employeeCount; $number++) {
        $employeeId = 'SCALE-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $ids[] = $employeeId;
        $employeeRows[] = [
            'employee_id' => $employeeId,
            'first_name' => 'Payroll',
            'last_name' => 'Employee '.$number,
            'full_name' => 'Payroll Employee '.$number,
            'email' => 'scale.employee.'.$number.'@example.test',
            'gender' => $number % 2 === 0 ? 'Female' : 'Male',
            'joining_date' => '2020-01-01',
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
        ];
    }
    $insertRows('employees', $employeeRows);
    expect(Employee::query()->whereIn('employee_id', $ids)->count())->toBe($employeeCount);

    $salaryRows = [];
    $policyRows = [];
    foreach ($ids as $employeeId) {
        $salaryRows[] = [
            'employee_id' => $employeeId,
            'basic_salary' => 20000,
            'gross_salary' => 30000,
            'house_rent' => 6000,
            'medical_allowance' => 1500,
            'other_allowance' => 2500,
            'net_salary' => 30000,
            'effective_from' => '2020-01-01',
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $policyRows[] = [
            'employee_id' => $employeeId,
            'grace_time_in' => 15,
            'grace_time_out' => 15,
            'shift_type' => 'Fixed',
            'is_punishable' => false,
            'duty_hours' => 8,
            'auto_shift' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    $insertRows('employee_salaries', $salaryRows);
    $insertRows('employee_policies', $policyRows);

    $dayShift = RosterShift::factory()->create([
        'shift_name' => 'Scale Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
        'is_fallback' => false,
    ]);
    $nightShift = RosterShift::factory()->create([
        'shift_name' => 'Scale Night Shift',
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'punch_in_window_start' => '21:00:00',
        'punch_in_window_end' => '23:00:00',
        'crosses_midnight' => true,
        'max_duration_hours' => 24,
        'is_fallback' => false,
    ]);

    $rosterRows = [];
    $offRows = [];
    foreach ($ids as $index => $employeeId) {
        $shift = $index < 500 ? $dayShift : $nightShift;
        $rosterRows[] = [
            'employee_id' => $employeeId,
            'shift_id' => $shift->id,
            'roster_date' => '2026-10-08',
            'scheduled_start_time' => $shift->start_time,
            'scheduled_end_time' => $shift->end_time,
            'status' => 'Scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $offRows[] = [
            'employee_id' => $employeeId,
            'shift_id' => null,
            'roster_date' => '2026-10-07',
            'status' => 'Off',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    $insertRows('roster_duties', $rosterRows);
    $insertRows('roster_duties', $offRows);

    $unpaidLeave = LeaveType::query()->create([
        'name' => 'Scale unpaid leave',
        'code' => 'SCALE-UL',
        'days_per_year' => 0,
        'is_paid' => false,
        'is_active' => true,
    ]);
    $leaveRows = [];
    foreach ($ids as $employeeId) {
        $leaveRows[] = [
            'employee_id' => $employeeId,
            'leave_type_id' => $unpaidLeave->id,
            'from_date' => '2026-10-05',
            'to_date' => '2026-10-05',
            'days' => 1,
            'is_half_day' => false,
            'reason' => '1000-employee end-to-end scenario',
            'status' => 'Approved',
            'approved_by' => $approver->id,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    $insertRows('leave_requests', $leaveRows);
    Holiday::query()->create([
        'name' => 'Scale public holiday',
        'holiday_date' => '2026-10-06',
        'holiday_type' => 'Public Holiday',
    ]);

    // One acknowledged notice is included in the HRM flow; it is informational and does
    // not silently erase or alter the source punch records used by payroll.
    $notice = app(InformedRequestService::class)->create([
        'employee_id' => $ids[0],
        'informed_date' => '2026-10-08',
        'informed_type' => 'On Duty',
        'expected_time' => '09:00:00',
        'reason' => 'Approved field assignment notice for the test employee.',
        'status' => 'Pending',
    ]);
    $this->actingAs($approver);
    $notice = app(InformedRequestService::class)->acknowledge($notice);
    expect($notice->status)->toBe('Acknowledged');

    $attendanceLogs = [];
    foreach ($ids as $index => $employeeId) {
        // The first 50 employees work the holiday, proving extra-duty approval separately
        // from regular weekday overtime. All employees also have one day/night shift pair.
        if ($index < 50) {
            foreach (['2026-10-06 13:00:00', '2026-10-06 15:00:00'] as $punchTime) {
                $attendanceLogs[] = [
                    'employee_id' => $employeeId,
                    'device_user_id' => 'SCALE-'.$employeeId,
                    'punch_time' => $punchTime,
                    'punch_type' => 'AUTO',
                    'is_processed' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $punches = $index < 500
            ? ['2026-10-08 09:00:00', '2026-10-08 19:00:00']
            : ['2026-10-08 22:00:00', '2026-10-09 08:00:00'];
        foreach ($punches as $punchTime) {
            $attendanceLogs[] = [
                'employee_id' => $employeeId,
                'device_user_id' => 'SCALE-'.$employeeId,
                'punch_time' => $punchTime,
                'punch_type' => 'AUTO',
                'is_processed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    $insertRows('attendance_logs', $attendanceLogs);

    $processing = app(AttendanceProcessingService::class);
    $processed = $processing->processPending(2500);
    expect($processed['fetched'])->toBe(2100)
        ->and($processed['processed'])->toBe(2100)
        ->and($processed['errors'])->toBe(0);
    expect(AttendancePair::query()->whereIn('employee_id', $ids)->whereNotNull('punch_out')->count())->toBe(1050);
    expect(AttendanceLog::query()->whereIn('employee_id', $ids)->where('is_processed', true)->count())->toBe(2100);
    $dayPair = AttendancePair::query()->where('employee_id', $ids[0])->whereDate('duty_date', '2026-10-08')->firstOrFail();
    $nightPair = AttendancePair::query()->where('employee_id', $ids[500])->whereDate('duty_date', '2026-10-08')->firstOrFail();
    expect((int) $dayPair->shift_id)->toBe((int) $dayShift->id)
        ->and($dayPair->punch_out->toDateString())->toBe('2026-10-08')
        ->and((int) $nightPair->shift_id)->toBe((int) $nightShift->id)
        ->and($nightPair->punch_out->toDateString())->toBe('2026-10-09');

    $dayDaily = AttendanceDaily::query()->whereIn('employee_id', $ids)->whereDate('duty_date', '2026-10-08')->get()->keyBy('employee_id');
    expect($dayDaily)->toHaveCount($employeeCount)
        ->and((float) $dayDaily->get($ids[0])->grand_total_hours)->toBe(10.0)
        ->and((float) $dayDaily->get($ids[0])->total_overtime_hours)->toBe(2.0)
        ->and((float) $dayDaily->get($ids[500])->grand_total_hours)->toBe(10.0);

    $reconciled = app(AttendanceDayReconciliationService::class)->reconcile('2026-10-05', '2026-10-08');
    expect($reconciled['leave'])->toBe($employeeCount)
        ->and($reconciled['holiday'])->toBe($employeeCount - 50)
        ->and($reconciled['off_day'])->toBe($employeeCount);
    expect(AttendanceDaily::query()->whereIn('employee_id', $ids)->whereDate('duty_date', '2026-10-05')->where('status', 'On Leave')->count())->toBe($employeeCount)
        ->and(AttendanceDaily::query()->whereIn('employee_id', $ids)->whereDate('duty_date', '2026-10-06')->where('status', 'Holiday')->count())->toBe($employeeCount - 50)
        ->and(AttendanceDaily::query()->whereIn('employee_id', $ids)->whereDate('duty_date', '2026-10-06')->where('status', 'Extra Duty')->count())->toBe(50)
        ->and(AttendanceDaily::query()->whereIn('employee_id', $ids)->whereDate('duty_date', '2026-10-07')->where('status', 'Off Day')->count())->toBe($employeeCount);

    // Apply an auditable 5% salary increase to all 1000 employees before payroll.
    $increments = app(SalaryIncrementService::class);
    $incrementResult = $increments->createForEmployees([
        'increment_type' => 'percentage',
        'increment_value' => 5,
        'effective_date' => '2026-10-01',
        'reason' => 'Annual 1000-employee end-to-end test increment.',
    ], $ids, $requester->id);
    expect($incrementResult['created'])->toHaveCount($employeeCount)
        ->and($incrementResult['skipped'])->toBe(0);
    foreach ($incrementResult['created'] as $increment) {
        $increments->approve($increment, $approver->id, 'Approved in 1000-employee integration flow.');
    }
    expect(SalaryIncrement::query()->where('status', 'applied')->count())->toBe($employeeCount);
    expect((float) EmployeeSalary::query()->where('employee_id', $ids[0])->whereDate('effective_from', '2026-10-01')->value('basic_salary'))->toBe(21000.0);

    // Exercise recurring salary adjustment templates, fixed monthly tax, PF, and a 0% loan.
    $adjustments = app(PayrollAdjustmentService::class);
    $adjustments->create([
        'scope' => 'global', 'adjustment_type' => 'benefit', 'name' => 'Scale salary adjustment',
        'code' => 'SCALE-ADJ', 'calculation_type' => 'fixed', 'amount' => 500,
        'percentage_base' => 'basic', 'auto_apply' => true, 'taxable' => true,
        'pf_eligible' => false, 'effective_from' => '2020-01-01',
    ], $requester->id);
    $adjustments->create([
        'scope' => 'global', 'adjustment_type' => 'deduction', 'name' => 'Scale recurring deduction',
        'code' => 'SCALE-DED', 'calculation_type' => 'fixed', 'amount' => 300,
        'percentage_base' => 'gross', 'auto_apply' => true, 'effective_from' => '2020-01-01',
    ], $requester->id);
    expect(SalaryBenefitTemplate::query()->where('code', 'SCALE-ADJ')->exists())->toBeTrue()
        ->and(SalaryDeductionTemplate::query()->where('code', 'SCALE-DED')->exists())->toBeTrue();

    $tax = app(TaxConfigurationService::class)->update([
        'name' => 'Scale fixed tax', 'mode' => 'fixed', 'fixed_monthly_tax' => 750,
        'annual_exemption' => 0, 'taxable_salary_percent' => 100,
        'effective_from' => '2020-01-01', 'effective_to' => null,
    ], $requester->id, 'Fixed monthly withholding configuration for the 1000-employee integration flow.');
    expect($tax->mode)->toBe('fixed')
        ->and(PayrollTaxAudit::query()->where('subject_id', $tax->id)->exists())->toBeTrue();

    $pfPolicy = PfPolicy::query()->create([
        'name' => 'Scale PF policy', 'employee_contribution_percent' => 5,
        'employer_contribution_percent' => 5, 'minimum_service_months' => 0,
        'contribution_base' => 'basic', 'is_active' => true, 'effective_from' => '2020-01-01',
    ]);
    expect($pfPolicy->exists)->toBeTrue();

    $payrollIds = array_slice($ids, 0, $employeeCount - 1);
    $loanRows = [];
    foreach ($payrollIds as $employeeId) {
        $loanRows[] = [
            'employee_id' => $employeeId,
            'employee_name' => 'Payroll '.substr($employeeId, 6),
            'principal_amount' => 1000,
            'interest_rate' => 0,
            'installment_count' => 1,
            'installment_amount' => 1000,
            'first_due_date' => '2026-10-10',
            'status' => 'pending',
            'requested_by' => $requester->id,
            'purpose' => 'Scale test 0% monthly loan.',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    $insertRows('payroll_loans', $loanRows);
    $loansByEmployee = PayrollLoan::query()->whereIn('employee_id', $payrollIds)->get()->keyBy('employee_id');
    $loanApproval = app(ApprovePayrollLoanAction::class);
    foreach ($loansByEmployee as $loan) {
        $loanApproval->approve($loan, $approver->id, 'Approved interest-free one-installment loan.');
    }
    expect(PayrollLoan::query()->whereIn('employee_id', $payrollIds)->where('status', 'active')->count())->toBe($employeeCount - 1)
        ->and(PayrollLoanInstallment::query()->whereIn('payroll_loan_id', $loansByEmployee->pluck('id'))->count())->toBe($employeeCount - 1);
    $loanAllocation = app(PayrollLoanService::class)->allocateMonthlyInstallments(2026, 10, 'all', [], $approver->id);
    expect($loanAllocation['scope'])->toBe('all')
        ->and($loanAllocation['allocated'])->toBe($employeeCount - 1)
        ->and((float) $loanAllocation['amount'])->toBe(999000.0);

    // One employee leaves at the end of October: that employee receives a final
    // settlement instead of also receiving an ordinary monthly salary slip.
    EmployeeResignation::query()->create([
        'employee_id' => $leaverId,
        'last_working_date' => '2026-10-31',
        'reason' => 'End-to-end final-settlement case.',
        'status' => 'Approved',
        'requested_by' => $requester->id,
        'approved_by' => $approver->id,
        'approved_at' => $now,
    ]);

    // Approve editable hours for all 1000 overtime requests and all 50 holiday extra-duty requests.
    $overtimeService = app(RosterOvertimeService::class);
    $overtimeService->syncFromAttendance('Overtime', '2026-10-08', '2026-10-08');
    $overtimeService->syncFromAttendance('Extra Duty', '2026-10-06', '2026-10-06');
    $overtimeRequests = RosterOvertime::query()->whereDate('overtime_date', '2026-10-08')->where('overtime_type', 'Overtime')->orderBy('id')->get();
    $extraDutyRequests = RosterOvertime::query()->whereDate('overtime_date', '2026-10-06')->where('overtime_type', 'Extra Duty')->orderBy('id')->get();
    expect($overtimeRequests)->toHaveCount($employeeCount)
        ->and($extraDutyRequests)->toHaveCount(50);
    foreach ($overtimeRequests as $request) {
        $overtimeService->approve($request, 1.5, $approver->id, 'Reviewed and approved at 1.5 hours.');
    }
    foreach ($extraDutyRequests as $request) {
        $overtimeService->approve($request, 1.0, $approver->id, 'Reviewed holiday extra duty at 1 hour.');
    }
    expect(RosterOvertime::query()->whereDate('overtime_date', '2026-10-08')->where('status', 'Approved')->count())->toBe($employeeCount)
        ->and(RosterOvertime::query()->whereDate('overtime_date', '2026-10-06')->where('status', 'Approved')->count())->toBe(50);

    $bonusPolicy = FestivalBonusPolicy::query()->create([
        'name' => 'Scale festival bonus', 'calculation_type' => 'percentage', 'amount' => 50,
        'calculation_base' => 'basic', 'minimum_service_months' => 6,
        'bonuses_per_year' => 2, 'is_active' => true,
    ]);
    GratuityPolicy::query()->create([
        'name' => 'Scale gratuity policy', 'minimum_service_years' => 5,
        'days_per_service_year' => 15, 'calculation_base' => 'basic', 'is_active' => true,
    ]);

    Bus::fake();
    $salarySheetService = app(PayrollSalarySheetService::class);
    $salarySheet = $salarySheetService->dispatch(2026, 10, $requester->id, false);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($salarySheet->id);
    $salarySheet = PayrollSalarySheet::query()->findOrFail($salarySheet->id);
    expect($salarySheet->status)->toBe('ready')
        ->and($salarySheet->employee_count)->toBe($employeeCount - 1)
        ->and($salarySheet->processed_count)->toBe($employeeCount - 1)
        ->and($salarySheet->slips()->count())->toBe($employeeCount - 1);

    $sampleSlip = PayrollSlip::query()->where('salary_sheet_id', $salarySheet->id)->where('employee_id', $ids[0])->firstOrFail();
    $sampleBreakdown = $sampleSlip->breakdown_json;
    $sampleCutlist = $salarySheet->cutlist->items()->where('employee_id', $ids[0])->firstOrFail();
    expect((int) $sampleCutlist->unpaid_leave_days)->toBe(1)
        ->and((int) $sampleCutlist->approved_overtime_minutes)->toBe(90)
        ->and((int) $sampleCutlist->approved_extra_duty_minutes)->toBe(60)
        ->and((float) $sampleCutlist->unpaid_leave_deduction)->toBe(1050.0);
    expect($sampleBreakdown['tax']['mode'])->toBe('global_fixed')
        ->and((float) $sampleBreakdown['tax']['monthly_tax'])->toBe(750.0)
        ->and(collect($sampleBreakdown['benefits'])->pluck('code')->all())->toContain('SCALE-ADJ', 'approved-overtime', 'approved-extra-duty')
        ->and(collect($sampleBreakdown['recurring_deductions'])->pluck('code')->all())->toContain('SCALE-DED')
        ->and($sampleBreakdown['provident_fund']['eligible'])->toBeTrue()
        ->and((float) $sampleBreakdown['provident_fund']['employee_contribution'])->toBe(1050.0)
        ->and((float) $sampleBreakdown['provident_fund']['employer_contribution'])->toBe(1050.0)
        ->and($sampleBreakdown['loan_installments'])->toHaveCount(1)
        ->and((float) $sampleBreakdown['loan_installments'][0]['amount'])->toBe(1000.0);

    $salarySheet = $salarySheetService->release($salarySheet, $approver->id);
    expect($salarySheet->status)->toBe('released');
    app(MarkPayrollSlipPaymentAction::class)->execute($sampleSlip, 'paid', $approver->id);
    expect(PfLedger::query()->where('reference_type', 'payroll_slip')->where('reference_id', $sampleSlip->id)->count())->toBe(2)
        ->and(PayrollLoanInstallment::query()->where('payroll_slip_id', $sampleSlip->id)->where('status', 'paid')->count())->toBe(1)
        ->and(PayrollLoan::query()->where('employee_id', $ids[0])->value('status'))->toBe('completed');

    $bonus = app(PayrollBonusService::class)->generate($bonusPolicy->id, 2026, 10, $requester->id);
    expect($bonus->status)->toBe('generated')
        ->and($bonus->employee_count)->toBe($employeeCount - 1)
        ->and($bonus->items()->count())->toBe($employeeCount - 1);
    $bonus = app(PayrollBonusService::class)->release($bonus, $approver->id);
    expect($bonus->status)->toBe('released');

    $settlementService = app(PayrollSettlementService::class);
    $settlement = $settlementService->create([
        'employee_id' => $leaverId,
        'settlement_date' => '2026-11-01',
        'last_working_date' => '2026-10-31',
        'leave_encashment' => 1000,
        'other_benefits' => 500,
        'deductions' => 250,
        'leave_encashment_reason' => 'Unused leave balance encashment.',
        'other_benefits_reason' => 'Final month benefit.',
        'deductions_reason' => 'Final reconciliation deduction.',
    ], $requester->id);
    expect($settlement->status)->toBe('pending')
        ->and((float) $settlement->gratuity_amount)->toBeGreaterThan(0)
        ->and((float) $settlement->net_settlement)->toBeGreaterThan(0);
    $settlement = $settlementService->approve($settlement, $approver->id);
    $settlement = $settlementService->markPaid($settlement, $approver->id);
    expect($settlement->status)->toBe('paid')
        ->and((float) $settlement->breakdown_json['salary_proration']['gross_due'])->toBe(31500.0)
        ->and((float) $settlement->breakdown_json['net_settlement'])->toBe((float) $settlement->net_settlement);
});

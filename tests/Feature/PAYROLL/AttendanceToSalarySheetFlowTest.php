<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\RosterOvertimeService;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Models\SalaryBenefitTemplate;
use Modules\PAYROLL\Models\SalaryDeductionTemplate;
use Modules\PAYROLL\Models\TaxConfiguration;
use Modules\PAYROLL\Models\TaxSlab;
use Modules\PAYROLL\Services\PayrollSalarySheetService;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('attendance logs flow through daily reconciliation and payroll components into a released salary sheet', function (): void {
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));

    $department = Department::factory()->create();
    $employee = Employee::factory()->create([
        'department_id' => $department->id,
        'joining_date' => '2020-01-01',
        'allow_overtime' => true,
        'duty_hours' => 8,
        'status' => 'Active',
        'is_active' => true,
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->employee_id,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 6000,
        'medical_allowance' => 1500,
        'other_allowance' => 2500,
        'net_salary' => 30000,
        'effective_from' => '2020-01-01',
        'is_current' => true,
    ]);
    EmployeePolicy::factory()->create([
        'employee_id' => $employee->employee_id,
        'shift_type' => 'Fixed',
        'duty_hours' => 8,
        'is_punishable' => false,
        'auto_shift' => true,
    ]);

    $shift = RosterShift::factory()->create([
        'shift_name' => 'E2E Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
    RosterDuty::factory()->create([
        'employee_id' => $employee->employee_id,
        'shift_id' => $shift->id,
        'roster_date' => '2026-10-08',
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Scheduled',
    ]);

    // One biometric IN/OUT pair becomes an attendance pair and an attendance-daily row.
    foreach (['2026-10-08 09:00:00', '2026-10-08 19:00:00'] as $punchTime) {
        $log = AttendanceLog::query()->create([
            'employee_id' => $employee->employee_id,
            'device_user_id' => 'E2E-'.$employee->employee_id,
            'punch_time' => $punchTime,
            'punch_type' => 'AUTO',
            'is_processed' => false,
        ]);
        $result = app(AttendanceProcessingService::class)->processSingle($log);
        expect($result['status'])->toBe('processed');
    }

    expect(AttendanceLog::query()->where('employee_id', $employee->employee_id)->where('is_processed', true)->count())->toBe(2);
    expect(AttendancePair::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-08')->whereNotNull('punch_out')->exists())->toBeTrue();
    $daily = AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-08')->firstOrFail();
    expect((float) $daily->grand_total_hours)->toBe(10.0);
    expect((float) $daily->total_overtime_hours)->toBeGreaterThan(0.5);

    // Create payroll source records for unpaid leave, a public holiday, and a rostered off day.
    $unpaidLeave = LeaveType::query()->create([
        'name' => 'E2E Unpaid Leave', 'code' => 'E2E-UL', 'days_per_year' => 0,
        'is_paid' => false, 'is_active' => true,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employee->employee_id,
        'leave_type_id' => $unpaidLeave->id,
        'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => 1,
        'is_half_day' => false, 'status' => 'Approved',
    ]);
    Holiday::query()->create(['name' => 'E2E Public Holiday', 'holiday_date' => '2026-10-06', 'holiday_type' => 'Public Holiday']);
    RosterDuty::factory()->create([
        'employee_id' => $employee->employee_id,
        'roster_date' => '2026-10-07',
        'status' => 'Off',
    ]);

    $reconciliation = app(AttendanceDayReconciliationService::class)->reconcile('2026-10-05', '2026-10-08');
    expect($reconciliation['leave'])->toBe(1)
        ->and($reconciliation['holiday'])->toBe(1)
        ->and($reconciliation['off_day'])->toBe(1);
    expect(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-05')->value('status'))->toBe('On Leave')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-06')->value('status'))->toBe('Holiday')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-07')->value('status'))->toBe('Off Day');

    // Only a reviewed attendance-derived overtime request is eligible for the payroll cutlist.
    $overtimeService = app(RosterOvertimeService::class);
    $overtimeService->syncFromAttendance('Overtime', '2026-10-08', '2026-10-08');
    $overtime = RosterOvertime::query()->where('employee_id', $employee->employee_id)->whereDate('overtime_date', '2026-10-08')->firstOrFail();
    expect(app(AttendancePayrollDataProviderInterface::class)->summaryForEmployee($employee->employee_id, '2026-10-01', '2026-10-31')->approvedOvertimeHours)->toBe(0.0);
    $overtimeService->approve($overtime, 1.5, null, 'E2E approved hours');
    expect(app(AttendancePayrollDataProviderInterface::class)->summaryForEmployee($employee->employee_id, '2026-10-01', '2026-10-31')->approvedOvertimeHours)->toBe(1.5);

    SalaryBenefitTemplate::query()->create([
        'name' => 'E2E taxable benefit', 'code' => 'e2e-benefit', 'calculation_type' => 'fixed',
        'amount' => 1000, 'percentage_base' => 'basic', 'auto_apply' => true,
        'taxable' => true, 'pf_eligible' => false, 'is_active' => true,
        'effective_from' => '2020-01-01',
    ]);
    SalaryDeductionTemplate::query()->create([
        'name' => 'E2E recurring deduction', 'code' => 'e2e-deduction', 'calculation_type' => 'fixed',
        'amount' => 500, 'percentage_base' => 'gross', 'auto_apply' => true,
        'is_active' => true, 'effective_from' => '2020-01-01',
    ]);
    $tax = TaxConfiguration::query()->create([
        'name' => 'E2E progressive policy', 'mode' => 'progressive', 'fixed_monthly_tax' => 0,
        'annual_exemption' => 0, 'taxable_salary_percent' => 100, 'is_active' => true,
        'effective_from' => '2020-01-01',
    ]);
    TaxSlab::query()->create([
        'tax_configuration_id' => $tax->id, 'lower_limit' => 0, 'upper_limit' => null,
        'rate_percent' => 10, 'sort_order' => 0,
    ]);
    $pfPolicy = PfPolicy::query()->create([
        'name' => 'E2E PF policy', 'employee_contribution_percent' => 5,
        'employer_contribution_percent' => 5, 'minimum_service_months' => 0,
        'contribution_base' => 'basic', 'is_active' => true, 'effective_from' => '2020-01-01',
    ]);
    expect($pfPolicy->exists)->toBeTrue();

    $loan = PayrollLoan::query()->create([
        'employee_id' => $employee->employee_id, 'principal_amount' => 2000,
        'interest_rate' => 0, 'installment_count' => 1, 'installment_amount' => 2000,
        'first_due_date' => '2026-10-10', 'status' => 'active', 'purpose' => 'E2E zero-interest loan',
    ]);
    $installment = PayrollLoanInstallment::query()->create([
        'payroll_loan_id' => $loan->id, 'installment_number' => 1,
        'due_date' => '2026-10-10', 'amount' => 2000, 'status' => 'scheduled',
    ]);

    Bus::fake();
    $salarySheet = app(PayrollSalarySheetService::class)->dispatch(2026, 10, null, false);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($salarySheet->id);

    $salarySheet = PayrollSalarySheet::query()->findOrFail($salarySheet->id);
    expect($salarySheet->status)->toBe('ready')
        ->and($salarySheet->employee_count)->toBe(1)
        ->and($salarySheet->processed_count)->toBe(1)
        ->and($salarySheet->cutlist->status)->toBe('generated');

    $slip = PayrollSlip::query()->where('salary_sheet_id', $salarySheet->id)->where('employee_id', $employee->employee_id)->firstOrFail();
    $breakdown = $slip->breakdown_json;
    $cutlistItem = $salarySheet->cutlist->items()->where('employee_id', $employee->employee_id)->firstOrFail();

    expect((float) $cutlistItem->unpaid_leave_days)->toBe(1.0)
        ->and((float) $cutlistItem->unpaid_leave_deduction)->toBe(1000.0)
        ->and((int) $cutlistItem->approved_overtime_minutes)->toBe(90)
        ->and((float) $cutlistItem->overtime_pay)->toBe(125.0);
    expect(collect($breakdown['benefits'])->pluck('code')->all())->toContain('e2e-benefit', 'approved-overtime')
        ->and(collect($breakdown['recurring_deductions'])->pluck('code')->all())->toContain('e2e-deduction');
    expect($breakdown['tax']['monthly_tax'])->toBe(3112.5)
        ->and((float) $breakdown['tax']['annual_tax'])->toBe(37350.0)
        ->and($breakdown['tax']['allocation']['method'])->toContain('annual tax divided by 12');
    expect($breakdown['provident_fund']['eligible'])->toBeTrue()
        ->and((float) $breakdown['provident_fund']['employee_contribution'])->toBe(1000.0)
        ->and((float) $breakdown['provident_fund']['employer_contribution'])->toBe(1000.0);
    expect($breakdown['loan_installments'])->toHaveCount(1)
        ->and((float) $breakdown['loan_installments'][0]['amount'])->toBe(2000.0);
    expect(round((float) $slip->gross_salary, 2))->toBe(31125.0)
        ->and(round((float) $slip->total_benefits, 2))->toBe(1125.0)
        ->and(round((float) $slip->total_deductions, 2))->toBe(7612.5)
        ->and(round((float) $slip->net_salary, 2))->toBe(23512.5);

    // Release/payment posts the PF ledger and settles the attached zero-interest installment.
    $salarySheet = app(PayrollSalarySheetService::class)->release($salarySheet, null);
    app(MarkPayrollSlipPaymentAction::class)->execute($slip, 'paid', null);
    expect(PfLedger::query()->where('reference_type', 'payroll_slip')->where('reference_id', $slip->id)->count())->toBe(2)
        ->and((float) $installment->fresh()->amount)->toBe(2000.0)
        ->and($installment->fresh()->status)->toBe('paid')
        ->and($loan->fresh()->status)->toBe('completed');
});

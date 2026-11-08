<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\EmployeeRecurringAdjustment;
use Modules\PAYROLL\Models\EmployeeTaxProfile;
use Modules\PAYROLL\Models\EmployeeTaxProfileVersion;
use Modules\PAYROLL\Models\PayrollCalculationSnapshot;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Models\PayrollPeriodExclusion;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Models\SalaryBenefitTemplate;
use Modules\PAYROLL\Models\SalaryDeductionTemplate;
use Modules\PAYROLL\Models\TaxConfiguration;

final class CapturePayrollCalculationSnapshotAction
{
    public function __construct(private readonly EmployeePayrollDataProviderInterface $employees) {}

    /** @return array<string, mixed> */
    public function execute(PayrollSalarySheet $sheet): array
    {
        return DB::transaction(function () use ($sheet): array {
            $existing = PayrollCalculationSnapshot::query()->where('salary_sheet_id', $sheet->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->snapshot_json;
            }

            $periodEnd = Carbon::create((int) $sheet->pay_year, (int) $sheet->pay_month, 1)->endOfMonth()->toDateString();
            $periodStart = Carbon::create((int) $sheet->pay_year, (int) $sheet->pay_month, 1)->startOfMonth()->toDateString();
            $periodExclusions = PayrollPeriodExclusion::query()->with(['employee:id,employee_id,full_name', 'creator:id,name'])
                ->where('pay_year', (int) $sheet->pay_year)->where('pay_month', (int) $sheet->pay_month)
                ->orderBy('employee_id')->get();
            $excludedEmployeeIds = $periodExclusions->pluck('employee_id')->map('strval')->all();
            $employeeSnapshot = $this->employees->employeesForPayroll($periodStart, $periodEnd, $excludedEmployeeIds)
                ->map(fn (EmployeePayrollData $employee) => $employee->toArray())->all();
            $employeeIds = array_map(fn (array $employee): string => (string) $employee['employeeId'], $employeeSnapshot);
            $settings = PayrollSetting::query()->first();
            $taxConfiguration = TaxConfiguration::query()->with('slabs')->effectiveOn($periodEnd)->orderByDesc('effective_from')->orderByDesc('id')->first();
            $pfPolicy = PfPolicy::query()->effectiveOn($periodEnd)->latest('effective_from')->first();
            $versionedTaxProfiles = EmployeeTaxProfileVersion::query()
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodEnd))
                ->orderByDesc('effective_from')->orderByDesc('id')->get()->unique('employee_id')
                ->mapWithKeys(fn ($row) => ['e:'.$row->employee_id => $row->only(['id', 'override_mode', 'fixed_tax_amount', 'fixed_tax_frequency', 'exemption_reason', 'effective_from', 'effective_to', 'change_reason'])])->all();
            $legacyTaxProfiles = EmployeeTaxProfile::query()
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodEnd))
                ->get()->mapWithKeys(fn ($row) => ['e:'.$row->employee_id => $row->only(['id', 'override_mode', 'fixed_tax_amount', 'fixed_tax_frequency', 'exemption_reason', 'effective_from', 'effective_to'])])->all();
            $taxProfiles = array_replace($legacyTaxProfiles, $versionedTaxProfiles);

            $snapshot = [
                'version' => 2,
                'captured_at' => now()->toIso8601String(),
                'period' => ['start' => $periodStart, 'end' => $periodEnd, 'year' => (int) $sheet->pay_year, 'month' => (int) $sheet->pay_month],
                'employees' => $employeeSnapshot,
                'period_exclusions' => $periodExclusions->map(fn (PayrollPeriodExclusion $exclusion) => [
                    'employee_id' => (string) $exclusion->employee_id,
                    'employee_name' => $exclusion->employee?->full_name,
                    'reason' => $exclusion->reason,
                    'created_by' => $exclusion->created_by,
                    'created_by_name' => $exclusion->creator?->name,
                    'created_at' => $exclusion->created_at?->toIso8601String(),
                ])->all(),
                'pf_accounts' => PfAccount::query()->whereIn('employee_id', $employeeIds)->get(['id', 'employee_id', 'status', 'pf_policy_id', 'eligible_from'])->mapWithKeys(fn ($row) => ['e:'.$row->employee_id => $row->only(['id', 'status', 'pf_policy_id', 'eligible_from'])])->all(),
                'loan_installments' => [],
                'settings' => $settings?->only(['currency', 'unpaid_leave_day_divisor']) ?? ['currency' => 'BDT', 'unpaid_leave_day_divisor' => 30],
                'benefit_templates' => SalaryBenefitTemplate::query()->active()->where('auto_apply', true)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart))
                    ->get()->map(fn ($row) => $row->only(['id', 'name', 'code', 'calculation_type', 'amount', 'percentage_base', 'taxable', 'pf_eligible']))->all(),
                'deduction_templates' => SalaryDeductionTemplate::query()->active()->where('auto_apply', true)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart))
                    ->get()->map(fn ($row) => $row->only(['id', 'name', 'code', 'calculation_type', 'amount', 'percentage_base']))->all(),
                'tax_configuration' => $taxConfiguration ? [
                    ...$taxConfiguration->only(['id', 'name', 'mode', 'fixed_monthly_tax', 'annual_exemption', 'taxable_salary_percent', 'effective_from', 'effective_to']),
                    'slabs' => $taxConfiguration->slabs->map(fn ($slab) => $slab->only(['lower_limit', 'upper_limit', 'rate_percent', 'sort_order']))->all(),
                ] : null,
                'pf_policy' => $pfPolicy?->only(['id', 'employee_contribution_percent', 'employer_contribution_percent', 'minimum_service_months', 'contribution_base']),
                'tax_profiles' => $taxProfiles,
                'employee_adjustments' => [],
            ];

            if ($employeeIds !== []) {
                $batch = PayrollLoanMonthlyBatch::query()
                    ->where('pay_year', (int) $sheet->pay_year)
                    ->where('pay_month', (int) $sheet->pay_month)
                    ->lockForUpdate()->first();

                if ($batch !== null && $batch->scope !== 'automatic') {
                    $allocations = PayrollLoanMonthlyAllocation::query()
                        ->with(['installment', 'loan:id,employee_id,status,interest_rate'])
                        ->where('batch_id', $batch->id)
                        ->where('status', 'allocated')
                        ->orderBy('employee_id')->orderBy('payroll_loan_id')->lockForUpdate()->get();

                    foreach ($allocations as $allocation) {
                        $item = $allocation->installment;
                        $loan = $allocation->loan;
                        if ($item === null || $loan === null
                            || $item->status !== 'scheduled'
                            || $loan->status !== 'active'
                            || (float) $loan->interest_rate !== 0.0
                            || $item->due_date->toDateString() > $periodEnd
                            || (string) $loan->employee_id !== (string) $allocation->employee_id
                            || (int) $item->payroll_loan_id !== (int) $loan->id
                            || round((float) $item->amount, 2) !== round((float) $allocation->amount, 2)
                            || ! in_array((string) $allocation->employee_id, $employeeIds, true)) {
                            throw new \RuntimeException('A monthly loan allocation no longer matches an active employee, scheduled installment, or payroll snapshot. Resolve the allocation before recalculating.');
                        }
                        $snapshot['loan_installments']['e:'.$allocation->employee_id][] = [
                            'installment_id' => $item->id,
                            'monthly_allocation_id' => $allocation->id,
                            'loan_id' => $item->payroll_loan_id,
                            'due_date' => $item->due_date->toDateString(),
                            'amount' => (float) $item->amount,
                        ];
                    }

                    if ($batch->status === 'open') {
                        $batch->update(['status' => 'locked', 'locked_by' => $sheet->created_by, 'locked_at' => now()]);
                    }
                } else {
                    // Backward-compatible automatic mode: one earliest due installment per loan and month.
                    PayrollLoanInstallment::query()->with('loan:id,employee_id,interest_rate')
                        ->whereHas('loan', fn ($query) => $query->whereIn('employee_id', $employeeIds)->whereIn('status', ['approved', 'active']))
                        ->whereDoesntHave('loan.installments', fn ($query) => $query->where('status', 'attached'))
                        ->whereDoesntHave('loan.monthlyAllocations', fn ($query) => $query->whereIn('status', ['allocated', 'attached']))
                        ->where('status', 'scheduled')->whereDate('due_date', '<=', $periodEnd)
                        ->orderBy('payroll_loan_id')->orderBy('due_date')->orderBy('installment_number')->orderBy('id')
                        ->lockForUpdate()->get()->groupBy('payroll_loan_id')->map(fn ($rows) => $rows->first())
                        ->each(function (PayrollLoanInstallment $item) use (&$snapshot): void {
                            if ($item->loan === null || (float) $item->loan->interest_rate !== 0.0) {
                                throw new \RuntimeException('A scheduled repayment is inconsistent with the 0% interest loan policy.');
                            }
                            $employeeId = (string) $item->loan->employee_id;
                            $snapshot['loan_installments']['e:'.$employeeId][] = [
                                'installment_id' => $item->id,
                                'monthly_allocation_id' => null,
                                'loan_id' => $item->payroll_loan_id,
                                'due_date' => $item->due_date->toDateString(),
                                'amount' => (float) $item->amount,
                            ];
                        });
                }
            }

            EmployeeRecurringAdjustment::query()->with(['benefitTemplate', 'deductionTemplate'])
                ->where('is_active', true)->whereDate('effective_from', '<=', $periodEnd)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart))
                ->orderBy('id')->get()->each(function (EmployeeRecurringAdjustment $row) use (&$snapshot): void {
                    $snapshot['employee_adjustments']['e:'.$row->employee_id][] = [
                        'id' => $row->id,
                        'adjustment_type' => $row->adjustment_type,
                        'name' => $row->name,
                        'calculation_type' => $row->calculation_type,
                        'amount' => $row->amount,
                        'percentage_base' => $row->percentage_base,
                        'benefit_template' => $row->benefitTemplate?->only(['code', 'taxable', 'pf_eligible']),
                        'deduction_template' => $row->deductionTemplate?->only(['code']),
                    ];
                });

            PayrollCalculationSnapshot::query()->create(['salary_sheet_id' => $sheet->id, 'snapshot_json' => $snapshot]);

            return $snapshot;
        }, 3);
    }
}

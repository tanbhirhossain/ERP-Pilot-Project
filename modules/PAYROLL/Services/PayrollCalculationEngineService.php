<?php

namespace Modules\PAYROLL\Services;

use Carbon\Carbon;
use Modules\PAYROLL\Actions\ApplyLoanInstallmentAction;
use Modules\PAYROLL\Actions\CalculateEmployeeTaxAction;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PayrollCutlistItem;

final class PayrollCalculationEngineService
{
    public function __construct(
        private readonly CalculateEmployeeTaxAction $taxCalculator,
        private readonly ApplyLoanInstallmentAction $loanInstallments,
    ) {}

    /** @return array<string, mixed> */
    public function calculate(EmployeePayrollData $employee, int $year, int $month, ?PayrollCutlistItem $cutlistItem, bool $includePunishments = false, array $snapshot = [], ?array $loanInstallments = null): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $base = [
            'basic' => $employee->basicSalary,
            'house_rent' => $employee->houseRent,
            'medical' => $employee->medicalAllowance,
            'other_allowance' => $employee->otherAllowance,
            'gross' => $employee->grossSalary,
        ];

        $prorationFactor = max(0.0, min(1.0, $employee->prorationFactor));
        $periodBase = $employee->periodAmounts !== []
            ? $employee->periodAmounts
            : array_map(fn (float $amount): float => round($amount * $prorationFactor, 2), $base);
        $benefits = $this->benefits($employee, $base, $snapshot, $prorationFactor);
        $deductions = $this->deductions($employee, $base, $snapshot, $prorationFactor);
        $cutlist = [
            'unpaid_leave_days' => (float) ($cutlistItem?->unpaid_leave_days ?? 0),
            'unpaid_leave' => (float) ($cutlistItem?->unpaid_leave_deduction ?? 0),
            'late_count' => (int) ($cutlistItem?->late_count ?? 0),
            'early_out_count' => (int) ($cutlistItem?->early_out_count ?? 0),
            'punishment_days' => (int) ($cutlistItem?->punishment_days ?? 0),
            'punishable' => (bool) ($cutlistItem?->punishable ?? false),
            'punishment' => $includePunishments ? (float) ($cutlistItem?->late_penalty_deduction ?? 0) : 0.0,
            'missing_punch_count' => (int) ($cutlistItem?->missing_punch_count ?? 0),
            'missing_punch' => (float) ($cutlistItem?->missing_punch_deduction ?? 0),
            'overtime_hours' => (float) ($cutlistItem?->approved_overtime_minutes ?? 0) / 60,
            'overtime_pay' => (float) ($cutlistItem?->overtime_pay ?? 0),
            'extra_duty_hours' => (float) ($cutlistItem?->approved_extra_duty_minutes ?? 0) / 60,
            'extra_duty_pay' => (float) ($cutlistItem?->extra_duty_pay ?? 0),
            'source' => $cutlistItem?->source_breakdown ?? [],
        ];
        if ($cutlist['overtime_pay'] > 0) $benefits[] = ['name' => 'Approved overtime', 'code' => 'approved-overtime', 'amount' => $cutlist['overtime_pay'], 'monthly_amount' => $cutlist['overtime_pay'], 'taxable' => true, 'pf_eligible' => false];
        if ($cutlist['extra_duty_pay'] > 0) $benefits[] = ['name' => 'Approved extra duty', 'code' => 'approved-extra-duty', 'amount' => $cutlist['extra_duty_pay'], 'monthly_amount' => $cutlist['extra_duty_pay'], 'taxable' => true, 'pf_eligible' => false];
        $gross = round($periodBase['gross'] + collect($benefits)->sum('amount'), 2);
        $taxableBenefits = collect($benefits)->where('taxable', true)->sum('monthly_amount');
        $taxableMonthlyIncome = $employee->grossSalary + (float) $taxableBenefits;

        $configuration = $snapshot['tax_configuration'] ?? null;
        $taxProfile = $snapshot['tax_profiles']['e:'.$employee->employeeId] ?? null;
        $tax = $this->taxCalculator->execute($taxableMonthlyIncome, $configuration, $taxProfile, $periodEnd);
        $tax['allocation'] = [
            'method' => 'annualized taxable monthly income, calculated under the selected tax profile/configuration, then allocated as annual tax divided by 12',
            'monthly_taxable_income' => round($taxableMonthlyIncome, 2),
            'annualized_taxable_income_before_exemption' => round($taxableMonthlyIncome * 12, 2),
            'annual_exemption' => (float) ($configuration['annual_exemption'] ?? 0),
            'taxable_salary_percent' => (float) ($configuration['taxable_salary_percent'] ?? 100),
            'configuration_id' => $configuration['id'] ?? null,
            'configuration_effective_from' => $configuration['effective_from'] ?? null,
            'profile_override' => $taxProfile['override_mode'] ?? 'global_config',
            'profile_reason' => $taxProfile['exemption_reason'] ?? $taxProfile['change_reason'] ?? null,
        ];

        $pf = $this->providentFund($employee, $periodEnd, $benefits, $snapshot['pf_policy'] ?? null, $prorationFactor, $snapshot['pf_accounts']['e:'.$employee->employeeId] ?? null);
        // Payroll batch execution passes the immutable, validated loan snapshot. Direct calculation calls without one contain no loan deduction.
        $loans = $loanInstallments ?? ['amount' => 0.0, 'installments' => []];
        $deductionTotal = round(
            collect($deductions)->sum('amount')
            + $tax['monthly_tax']
            + $pf['employee_contribution']
            + $loans['amount']
            + $cutlist['unpaid_leave']
            + $cutlist['punishment']
            + $cutlist['missing_punch'],
            2,
        );

        return [
            'basic_salary' => $periodBase['basic'],
            'gross_salary' => $gross,
            'total_benefits' => round(collect($benefits)->sum('amount'), 2),
            'total_deductions' => $deductionTotal,
            'net_salary' => round($gross - $deductionTotal, 2),
            'breakdown_json' => [
                'version' => 1,
                'currency' => (string) ($snapshot['settings']['currency'] ?? 'BDT'),
                'calculation_snapshot_captured_at' => $snapshot['captured_at'] ?? null,
                'period' => ['year' => $year, 'month' => $month, 'start' => $periodStart, 'end' => $periodEnd],
                'employee' => $employee->toArray(),
                'base_components' => $base,
                'period_base_components' => $periodBase,
                'salary_proration' => ['rule' => 'calendar-day weighted by effective salary segment', 'days_in_month' => $employee->daysInPeriod, 'payable_days' => $employee->payableDays, 'factor' => $prorationFactor, 'salary_segments' => $employee->salarySegments],
                'benefits' => $benefits,
                'recurring_deductions' => $deductions,
                'tax' => $tax,
                'provident_fund' => $pf,
                'loan_installments' => $loans['installments'],
                'cutlist' => $cutlist,
                'salary_sheet_mode' => ['with_punishment' => $includePunishments],
                'totals' => [
                    'gross' => $gross,
                    'deductions' => $deductionTotal,
                    'net' => round($gross - $deductionTotal, 2),
                ],
                'immutable_snapshot_created_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function benefits(EmployeePayrollData $employee, array $base, array $snapshot, float $prorationFactor): array
    {
        $items = [];
        foreach ($snapshot['benefit_templates'] ?? [] as $template) {
            $items[] = $this->templateItem((string) $template['name'], (string) $template['code'], (string) $template['calculation_type'], (float) $template['amount'], (string) $template['percentage_base'], $base, (bool) $template['taxable'], (bool) $template['pf_eligible'], $prorationFactor);
        }

        foreach ($snapshot['employee_adjustments']['e:'.$employee->employeeId] ?? [] as $adjustment) {
            if (($adjustment['adjustment_type'] ?? null) !== 'benefit') continue;
            $template = $adjustment['benefit_template'] ?? [];
            $items[] = $this->templateItem((string) $adjustment['name'], (string) ($template['code'] ?? 'employee-benefit'), (string) $adjustment['calculation_type'], (float) $adjustment['amount'], (string) $adjustment['percentage_base'], $base, (bool) ($template['taxable'] ?? true), (bool) ($template['pf_eligible'] ?? false), $prorationFactor);
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function deductions(EmployeePayrollData $employee, array $base, array $snapshot, float $prorationFactor): array
    {
        $items = [];
        foreach ($snapshot['deduction_templates'] ?? [] as $template) {
            $items[] = $this->templateItem((string) $template['name'], (string) $template['code'], (string) $template['calculation_type'], (float) $template['amount'], (string) $template['percentage_base'], $base, false, false, $prorationFactor);
        }

        foreach ($snapshot['employee_adjustments']['e:'.$employee->employeeId] ?? [] as $adjustment) {
            if (($adjustment['adjustment_type'] ?? null) !== 'deduction') continue;
            $template = $adjustment['deduction_template'] ?? [];
            $items[] = $this->templateItem((string) $adjustment['name'], (string) ($template['code'] ?? 'employee-deduction'), (string) $adjustment['calculation_type'], (float) $adjustment['amount'], (string) $adjustment['percentage_base'], $base, false, false, $prorationFactor);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function templateItem(string $name, string $code, string $calculationType, float $amount, string $percentageBase, array $base, bool $taxable, bool $pfEligible, float $prorationFactor): array
    {
        $baseAmount = match ($percentageBase) {
            'gross' => (float) ($base['gross'] ?? 0),
            'house_rent' => (float) ($base['house_rent'] ?? 0),
            'medical' => (float) ($base['medical'] ?? 0),
            'other_allowance' => (float) ($base['other_allowance'] ?? 0),
            default => (float) ($base['basic'] ?? 0),
        };
        $calculated = $calculationType === 'percentage'
            ? $baseAmount * ($amount / 100)
            : $amount;
        $monthlyAmount = round($calculated * max(0.0, min(1.0, $prorationFactor)), 2);

        return [
            'name' => $name,
            'code' => $code,
            'calculation_type' => $calculationType,
            'amount' => $monthlyAmount,
            'monthly_amount' => $monthlyAmount,
            'taxable' => $taxable,
            'pf_eligible' => $pfEligible,
        ];
    }

    /** @return array<string, mixed> */
    private function providentFund(EmployeePayrollData $employee, string $periodEnd, array $benefits, ?array $policy, float $prorationFactor, ?array $accountSnapshot): array
    {
        if ($policy === null || $employee->joiningDate === null) {
            return ['account_id' => null, 'policy_id' => null, 'eligible' => false, 'employee_contribution' => 0.0, 'employer_contribution' => 0.0];
        }

        $joiningDate = Carbon::parse($employee->joiningDate)->startOfDay();
        $periodDate = Carbon::parse($periodEnd)->startOfDay();
        if ($joiningDate->gt($periodDate)) {
            return ['account_id' => null, 'policy_id' => $policy['id'], 'eligible' => false, 'employee_contribution' => 0.0, 'employer_contribution' => 0.0];
        }
        $serviceMonths = $joiningDate->diffInMonths($periodDate);
        if ($serviceMonths < (int) $policy['minimum_service_months']) {
            return ['account_id' => null, 'policy_id' => $policy['id'], 'eligible' => false, 'employee_contribution' => 0.0, 'employer_contribution' => 0.0];
        }

        if ($accountSnapshot !== null) {
            $account = PfAccount::query()->lockForUpdate()->find($accountSnapshot['id']);
            if ($account === null) throw new \RuntimeException("Provident-fund account {$accountSnapshot['id']} disappeared after the payroll snapshot.");
            $accountStatusAtSnapshot = (string) $accountSnapshot['status'];
        } else {
            $account = PfAccount::query()->firstOrCreate(
                ['employee_id' => $employee->employeeId],
                ['employee_name' => $employee->name, 'pf_policy_id' => $policy['id'], 'eligible_from' => $joiningDate->copy()->addMonthsNoOverflow((int) $policy['minimum_service_months'])->toDateString(), 'status' => 'active'],
            );
            if (!$account->wasRecentlyCreated && $account->status !== 'active') {
                throw new \RuntimeException("Provident-fund account for {$employee->employeeId} was created or disabled after the payroll snapshot.");
            }
            $accountStatusAtSnapshot = 'active';
        }
        if ($accountStatusAtSnapshot !== 'active') {
            return ['account_id' => $account->id, 'policy_id' => $policy['id'], 'eligible' => false, 'employee_contribution' => 0.0, 'employer_contribution' => 0.0];
        }
        $eligibleFrom = $joiningDate->copy()->addMonthsNoOverflow((int) $policy['minimum_service_months'])->toDateString();
        if ($account->pf_policy_id !== $policy['id'] || $account->eligible_from?->toDateString() !== $eligibleFrom) {
            $account->update(['pf_policy_id' => $policy['id'], 'eligible_from' => $eligibleFrom]);
        }

        $base = match ($policy['contribution_base']) {
            'gross' => $employee->grossSalary * $prorationFactor,
            'house_rent' => $employee->houseRent * $prorationFactor,
            'pf_eligible' => ($employee->basicSalary * $prorationFactor) + (float) collect($benefits)->where('pf_eligible', true)->sum('amount'),
            default => $employee->basicSalary * $prorationFactor,
        };

        return [
            'account_id' => $account->id,
            'policy_id' => $policy['id'],
            'eligible' => true,
            'account_status_at_snapshot' => $accountStatusAtSnapshot,
            'contribution_base' => $policy['contribution_base'],
            'base_amount' => round($base, 2),
            'employee_percent' => (float) $policy['employee_contribution_percent'],
            'employer_percent' => (float) $policy['employer_contribution_percent'],
            'employee_contribution' => round($base * ((float) $policy['employee_contribution_percent'] / 100), 2),
            'employer_contribution' => round($base * ((float) $policy['employer_contribution_percent'] / 100), 2),
        ];
    }
}

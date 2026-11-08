<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;

final class CalculateEmployeeTaxAction
{
    /** @return array{monthly_tax: float, annual_taxable_income: float, annual_tax: float, mode: string, breakdown: array<int, array<string, float|string>>} */
    public function execute(float $monthlyTaxableIncome, ?array $configuration, ?array $profile, string $effectiveDate): array
    {
        if ($profile !== null && $this->profileIsEffective($profile, $effectiveDate)) {
            if (($profile['override_mode'] ?? null) === 'exempt') {
                return $this->result(0, $monthlyTaxableIncome * 12, 'exempt', []);
            }

            if (($profile['override_mode'] ?? null) === 'force_fixed') {
                $annual = (float) ($profile['fixed_tax_amount'] ?? 0);
                if (($profile['fixed_tax_frequency'] ?? 'monthly') === 'monthly') {
                    return $this->result($annual, $monthlyTaxableIncome * 12, 'employee_fixed_monthly', []);
                }

                return $this->result($annual / 12, $monthlyTaxableIncome * 12, 'employee_fixed_annual', []);
            }
        }

        if ($configuration === null) {
            return $this->result(0, $monthlyTaxableIncome * 12, 'unconfigured', []);
        }

        if (($configuration['mode'] ?? 'progressive') === 'fixed') {
            return $this->result((float) ($configuration['fixed_monthly_tax'] ?? 0), $monthlyTaxableIncome * 12, 'global_fixed', []);
        }

        $annualIncome = max(0, ($monthlyTaxableIncome * 12 * ((float) ($configuration['taxable_salary_percent'] ?? 100) / 100)) - (float) ($configuration['annual_exemption'] ?? 0));
        $annualTax = 0.0;
        $breakdown = [];

        foreach (($configuration['slabs'] ?? []) as $slab) {
            $lower = (float) ($slab['lower_limit'] ?? 0);
            $upperLimit = $slab['upper_limit'] ?? null;
            $upper = $upperLimit === null ? $annualIncome : (float) $upperLimit;
            $taxableInSlab = max(0, min($annualIncome, $upper) - $lower);
            $tax = round($taxableInSlab * ((float) ($slab['rate_percent'] ?? 0) / 100), 2);
            $annualTax += $tax;
            $breakdown[] = [
                'lower_limit' => $lower,
                'upper_limit' => $upperLimit === null ? 'unlimited' : $upper,
                'rate_percent' => (float) ($slab['rate_percent'] ?? 0),
                'taxable_amount' => round($taxableInSlab, 2),
                'annual_tax' => $tax,
                'monthly_tax' => round($tax / 12, 2),
            ];
        }

        return $this->result(round($annualTax / 12, 2), $annualIncome, 'progressive', $breakdown, round($annualTax, 2));
    }

    private function profileIsEffective(array $profile, string $date): bool
    {
        $day = Carbon::parse($date)->toDateString();
        $from = isset($profile['effective_from']) ? Carbon::parse($profile['effective_from'])->toDateString() : null;
        $to = isset($profile['effective_to']) ? Carbon::parse($profile['effective_to'])->toDateString() : null;

        return ($from === null || $from <= $day) && ($to === null || $to >= $day);
    }

    private function result(float $monthlyTax, float $annualIncome, string $mode, array $breakdown, ?float $annualTax = null): array
    {
        return [
            'monthly_tax' => round($monthlyTax, 2),
            'annual_taxable_income' => round($annualIncome, 2),
            'annual_tax' => $annualTax ?? round($monthlyTax * 12, 2),
            'mode' => $mode,
            'breakdown' => $breakdown,
        ];
    }
}

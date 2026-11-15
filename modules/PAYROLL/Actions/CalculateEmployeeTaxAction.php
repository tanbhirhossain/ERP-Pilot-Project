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

        /**
         * Slabs are bands of *gross* annual income; the exemption is a floor beneath them.
         *
         * The old formula was `annualIncome = gross - exemption` and then it measured the slabs from
         * zero. With the configured bands beginning at 0–350,000 @ 0%, that subtraction landed the
         * whole of a modest income inside a band that already said "untaxed", so it was applied
         * twice: an employee on ৳40,000/month (৳480,000/year) was charged ৳0 instead of ৳8,000.
         *
         * The two knobs must not both mean "the first 350,000 is free". So the bands stay exactly as
         * the operator configured them — in gross terms — and the relief is enforced as a floor:
         * nothing below the exemption threshold is ever taxed, whichever band it nominally falls in.
         * A policy whose first band already runs 0 → 350,000 @ 0% therefore produces the same answer
         * as one that omits that band entirely, which is the only way both are safe to type in.
         */
        $annualGross = $monthlyTaxableIncome * 12 * ((float) ($configuration['taxable_salary_percent'] ?? 100) / 100);
        $exemption = max(0, (float) ($configuration['annual_exemption'] ?? 0));
        $annualIncome = max(0, $annualGross);
        $annualTax = 0.0;
        $breakdown = [];

        foreach (($configuration['slabs'] ?? []) as $slab) {
            $configuredLower = (float) ($slab['lower_limit'] ?? 0);
            $upperLimit = $slab['upper_limit'] ?? null;
            $upper = $upperLimit === null ? $annualIncome : (float) $upperLimit;
            // The band's taxable portion starts at the higher of its own floor and the relief floor.
            $lower = max($configuredLower, $exemption);
            $taxableInSlab = max(0, min($annualIncome, $upper) - $lower);
            $tax = round($taxableInSlab * ((float) ($slab['rate_percent'] ?? 0) / 100), 2);
            $annualTax += $tax;
            $breakdown[] = [
                // The configured band, so the breakdown still reads like the policy screen.
                'lower_limit' => $configuredLower,
                'upper_limit' => $upperLimit === null ? 'unlimited' : (float) $upperLimit,
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

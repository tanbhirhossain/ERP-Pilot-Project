<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\PayrollSlip;

final class CalculateFestivalBonusAction
{
    /** @return array<string, mixed> */
    public function execute(FestivalBonusPolicy $policy, PayrollSlip $slip): array
    {
        $joiningDateValue = $slip->breakdown_json['employee']['joiningDate'] ?? null;
        $periodEnd = Carbon::create($slip->pay_year, $slip->pay_month, 1)->endOfMonth();
        $joiningDate = $joiningDateValue ? Carbon::parse($joiningDateValue) : null;
        $serviceMonths = $joiningDate && $joiningDate->lte($periodEnd) ? $joiningDate->diffInMonths($periodEnd) : 0;
        $eligible = $joiningDate !== null && $joiningDate->lte($periodEnd) && $serviceMonths >= (int) $policy->minimum_service_months;
        $breakdown = $slip->breakdown_json;
        $baseKey = $policy->calculation_base === 'gross' ? 'gross' : 'basic';
        $base = (float) ($breakdown['base_components'][$baseKey] ?? ($baseKey === 'gross' ? $slip->gross_salary : $slip->basic_salary));
        $amount = ! $eligible ? 0.0 : ($policy->calculation_type === 'percentage'
            ? $base * ((float) $policy->amount / 100)
            : (float) $policy->amount);

        return [
            'policy_id' => $policy->id,
            'policy' => $policy->name,
            'eligible' => $eligible,
            'service_months' => $serviceMonths,
            'calculation_base' => $policy->calculation_base,
            'amount' => round($amount, 2),
            'breakdown' => $breakdown,
        ];
    }
}

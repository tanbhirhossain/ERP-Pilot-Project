<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PayrollSetting;

final class PayrollSettingsService
{
    public function current(): PayrollSetting
    {
        return PayrollSetting::query()->firstOrCreate(['id' => 1], [
            'currency' => 'BDT',
            'punishment_days_per_salary_day' => 3,
            'overtime_multiplier' => 1.00,
            'extra_duty_multiplier' => 1.00,
            'missing_punch_penalty_amount' => 0,
            'unpaid_leave_day_divisor' => 30,
            'loan_approval_steps' => [['label' => 'Payroll', 'role' => 'Administrator']],
            'cutlist_review_required' => false,
        ]);
    }

    public function update(array $data): PayrollSetting
    {
        return DB::transaction(function () use ($data): PayrollSetting {
            $settings = $this->current();
            $steps = PayrollLoanApprovalSteps::parseInput((string) ($data['loan_approval_steps_text'] ?? 'Payroll=Administrator'));
            $settings->update([
                'currency' => $data['currency'],
                'punishment_days_per_salary_day' => $data['punishment_days_per_salary_day'],
                'overtime_multiplier' => $data['overtime_multiplier'],
                'extra_duty_multiplier' => $data['extra_duty_multiplier'],
                'missing_punch_penalty_amount' => $data['missing_punch_penalty_amount'],
                'unpaid_leave_day_divisor' => $data['unpaid_leave_day_divisor'],
                'loan_approval_steps' => $steps,
                'cutlist_review_required' => (bool) ($data['cutlist_review_required'] ?? false),
            ]);

            return $settings->refresh();
        });
    }
}

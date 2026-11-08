<?php

namespace Modules\PAYROLL\Services;

use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\SalaryIncrement;

final class PayrollDashboardService
{
    public function summary(): array
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('m');
        $sheet = PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->first();

        return [
            'current_period' => ['year' => $year, 'month' => $month],
            'current_sheet' => $sheet,
            'pending_increments' => SalaryIncrement::query()->where('status', 'pending')->count(),
            'pending_loans' => PayrollLoan::query()->where('status', 'pending')->count(),
            'held_slips' => PayrollSlip::query()->where('payment_status', 'hold')->count(),
            'pending_settlements' => PayrollSettlement::query()->where('status', 'pending')->count(),
            'recent_sheets' => PayrollSalarySheet::query()->latest('pay_year')->latest('pay_month')->limit(6)->get(),
        ];
    }
}

<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\Log;
use Modules\PAYROLL\Models\PayrollSlip;

final class PayrollPaymentPostingService
{
    public function __construct(private readonly PayrollJournalService $journals) {}

    public function postIfPaid(PayrollSlip $slip): void
    {
        try {
            $slip->refresh();
            if ($slip->payment_status !== 'paid') return;
            if ((float)$slip->net_salary <= 0) return;
            $bank = \Modules\ACCOUNTS\Models\Bank::where('is_active', true)->first();
            if (!$bank) { Log::warning('Payroll payment posting skipped: no company bank', ['slip_id'=>$slip->id]); return; }
            $bankAccount = \Modules\ACCOUNTS\Models\Account::find($bank->account_id);
            if (!$bankAccount) { Log::warning('Payroll payment posting skipped: bank account link missing', ['bank_id'=>$bank->id]); return; }
            $this->journals->postSalaryPayment($slip, (int)$bankAccount->id, $slip->paid_at?->toDateString() ?? now()->toDateString());
            Log::info('Payroll payment JV posted', ['slip_id'=>$slip->id,'amount'=>$slip->net_salary]);
        } catch (\Throwable $e) { Log::error('Payroll payment posting failed', ['slip_id'=>$slip->id,'error'=>$e->getMessage()]); }
    }
}

<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;

final class MarkPayrollSlipPaymentAction
{
    public function execute(PayrollSlip $slip, string $status, ?int $actorId): PayrollSlip
    {
        if (! in_array($status, ['unpaid', 'paid', 'hold'], true)) {
            throw new \InvalidArgumentException('Invalid payment status.');
        }

        return DB::transaction(function () use ($slip, $status, $actorId): PayrollSlip {
            $locked = PayrollSlip::query()->lockForUpdate()->findOrFail($slip->id);
            $sheet = PayrollSalarySheet::query()->lockForUpdate()->findOrFail($locked->salary_sheet_id);
            if ($sheet->status !== 'released') {
                throw ValidationException::withMessages(['payment_status' => 'The salary sheet must be released before changing a slip payment status.']);
            }
            if ($locked->payment_status === 'paid' && $status === 'paid') {
                return $locked;
            }
            if ($locked->payment_status === 'paid' && $status !== 'paid') {
                throw new \RuntimeException('A paid slip cannot be reverted. Use a separate reversal adjustment.');
            }

            if ($status === 'paid' && $locked->payment_status !== 'paid') {
                $this->postProvidentFund($locked, $actorId);
                $loanIds = PayrollLoanInstallment::query()->where('payroll_slip_id', $locked->id)->where('status', 'attached')->pluck('payroll_loan_id')->unique();
                PayrollLoanInstallment::query()
                    ->where('payroll_slip_id', $locked->id)
                    ->where('status', 'attached')
                    ->update(['status' => 'paid', 'paid_at' => now()]);
                \Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation::query()
                    ->where('payroll_slip_id', $locked->id)
                    ->where('status', 'attached')
                    ->update(['status' => 'paid', 'paid_at' => now()]);
                foreach ($loanIds as $loanId) {
                    $hasRemaining = PayrollLoanInstallment::query()->where('payroll_loan_id', $loanId)->whereIn('status', ['scheduled', 'attached'])->exists();
                    if (! $hasRemaining) {
                        \Modules\PAYROLL\Models\PayrollLoan::query()->whereKey($loanId)->where('status', 'active')->update(['status' => 'completed']);
                    }
                }
            }

            $locked->update([
                'payment_status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
                'paid_by' => $status === 'paid' ? $actorId : null,
            ]);

            $result = $locked->refresh();
            // ── Finance Bridge: auto-post payment JV (idempotent, never blocks HR) ──
            if ($status === 'paid') {
                try { app(\Modules\PAYROLL\Services\PayrollPaymentPostingService::class)->postIfPaid($result); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Payroll payment JV skipped: '.$e->getMessage(), ['slip_id'=>$result->id]); }
            }

            return $result;
        });
    }

    private function postProvidentFund(PayrollSlip $slip, ?int $actorId): void
    {
        $pf = $slip->breakdown_json['provident_fund'] ?? [];
        $accountId = $pf['account_id'] ?? null;
        if (! $accountId || empty($pf['eligible'])) {
            return;
        }

        $account = PfAccount::query()->lockForUpdate()->find($accountId);
        if ($account === null) {
            return;
        }

        foreach ([
            'employee_contribution' => (float) ($pf['employee_contribution'] ?? 0),
            'employer_contribution' => (float) ($pf['employer_contribution'] ?? 0),
        ] as $type => $amount) {
            if ($amount <= 0 || PfLedger::query()->where('reference_type', 'payroll_slip')->where('reference_id', $slip->id)->where('pf_account_id', $account->id)->where('entry_type', $type)->exists()) {
                continue;
            }

            PfLedger::query()->create([
                'pf_account_id' => $account->id,
                'entry_type' => $type,
                'amount' => $amount,
                'entry_date' => now()->toDateString(),
                'reference_type' => 'payroll_slip',
                'reference_id' => $slip->id,
                'description' => sprintf('Payroll PF contribution for %04d-%02d', $slip->pay_year, $slip->pay_month),
                'created_by' => $actorId,
            ]);

            $balanceField = $type === 'employee_contribution' ? 'employee_balance' : 'employer_balance';
            $account->increment($balanceField, $amount);
        }
    }
}

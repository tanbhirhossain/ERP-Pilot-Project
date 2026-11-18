<?php

namespace Modules\PAYROLL\Actions;

use Modules\HRM\Models\Employee\EmployeeSalary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Services\PayrollPaymentPostingService;

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
                PayrollLoanMonthlyAllocation::query()
                    ->where('payroll_slip_id', $locked->id)
                    ->where('status', 'attached')
                    ->update(['status' => 'paid', 'paid_at' => now()]);
                foreach ($loanIds as $loanId) {
                    $hasRemaining = PayrollLoanInstallment::query()->where('payroll_loan_id', $loanId)->whereIn('status', ['scheduled', 'attached'])->exists();
                    if (! $hasRemaining) {
                        PayrollLoan::query()->whereKey($loanId)->where('status', 'active')->update(['status' => 'completed']);
                    }
                }
            }

            // How this slip was actually paid, captured at the moment it was paid. The salary record's
            // payment_method is the intent and can be edited afterwards; this is the fact, and the
            // ledger posting below reads it so that a cash payment can never be booked as a bank one.
            $method = $status === 'paid' ? $this->paymentMethodFor($locked) : null;

            $locked->update([
                'payment_status' => $status,
                'payment_method' => $method,
                'paid_at' => $status === 'paid' ? now() : null,
                'paid_by' => $status === 'paid' ? $actorId : null,
            ]);

            $result = $locked->refresh();

            // Finance bridge: post the bank journal for the slip just marked paid.
            //
            // "Never blocks HR" is still true and still the point — a chart of accounts that cannot
            // take the journal must not stop a payroll officer paying people. What changed is that
            // the failure now lands on the slip as `payment_posting_error`, so a paid-but-unposted
            // slip is a queryable row instead of a log line (finding 35). Both halves matter: the
            // service rethrows so this layer gets to decide, and this layer decides "record, don't
            // block". Logged at error, not warning, because it is a divergence between payroll and
            // the ledger rather than an advisory skip.
            if ($status === 'paid') {
                try {
                    app(PayrollPaymentPostingService::class)->postIfPaid($result);
                } catch (\Throwable $e) {
                    Log::error('Payroll payment JV not posted: '.$e->getMessage(), [
                        'slip_id' => $result->id,
                        'sheet_id' => $result->salary_sheet_id,
                        'amount' => $result->net_salary,
                    ]);

                    $result->update(['payment_posting_error' => $e->getMessage()]);
                }
            }

            return $result->refresh();
        });
    }

    /**
     * 'cash' or 'bank' for this employee, taken from the salary record they are paid under.
     *
     * Deliberately a small, readable rule: the salary record says how this person is paid, and an
     * employee with no account number is a cash employee whether or not anyone remembered to set the
     * flag. That inference matters — it is the difference between a ledger that matches the till and
     * one that quietly credits a bank for money handed over in an envelope.
     */
    private function paymentMethodFor(PayrollSlip $slip): string
    {
        $salary = EmployeeSalary::query()
            ->where('employee_id', $slip->employee_id)
            ->orderByDesc('is_current')
            ->orderByDesc('effective_from')
            ->first();

        if ($salary === null) {
            return 'bank';
        }

        if ($salary->payment_method === 'cash' || $salary->payment_method === 'bank') {
            return $salary->payment_method;
        }

        // No flag set: an account number means the bank, its absence means the counter.
        return filled($salary->bank_account_no) ? 'bank' : 'cash';
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

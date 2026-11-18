<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\Log;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * Posts the bank journal for a slip that has been marked paid.
 *
 * This service used to wrap everything in `catch (\Throwable) { Log::error(...) }`, which is what
 * turned finding 35 from a constraint violation into a silent payroll divergence: the slip was paid,
 * the PF ledger was written, and the bank journal simply did not exist. It now rethrows, because the
 * caller decides whether a posting failure should block anything — `MarkPayrollSlipPaymentAction`
 * deliberately does not block HR, but it records the reason on the slip. A swallow here removed that
 * choice from every caller.
 *
 * The two advisory skips below (no active bank, bank with no ledger account) are recorded on the slip
 * as well. They are not exceptions — there is nothing for the officer to retry — but leaving them in
 * the log only would reproduce the same invisibility one layer down.
 */
final class PayrollPaymentPostingService
{
    public function __construct(private readonly PayrollJournalService $journals) {}

    public function postIfPaid(PayrollSlip $slip): void
    {
        $slip->refresh();

        if ($slip->payment_status !== 'paid' || (float) $slip->net_salary <= 0) {
            return;
        }

        $payAccount = $this->resolvePaymentAccount($slip);

        if ($payAccount === null) {
            return;
        }

        try {
            $this->journals->postSalaryPayment($slip, (int) $payAccount->id, $slip->paid_at?->toDateString() ?? now()->toDateString());
        } catch (\Throwable $e) {
            Log::error('Payroll payment posting failed', ['slip_id' => $slip->id, 'error' => $e->getMessage()]);

            throw new \DomainException(
                'The salary payment for slip #'.$slip->id.' could not be posted: '.$e->getMessage(),
                0,
                $e
            );
        }

        Log::info('Payroll payment JV posted', ['slip_id' => $slip->id, 'amount' => $slip->net_salary]);
    }

    /**
     * The ledger account the money actually left from: a bank, or the cash drawer.
     *
     * Every payment used to credit the first active bank regardless of how the employee was paid, so
     * paying an attendant in hand would still have credited Prime Bank — the till would never be
     * debited and the bank would show an outflow that never happened. The method comes from the
     * employee's salary record ('bank' or 'cash'); anything else falls back to the bank, which is what
     * the old code did, so nobody's behaviour changes silently.
     */
    private function resolvePaymentAccount(PayrollSlip $slip): ?Account
    {
        $method = EmployeeSalary::query()
            ->where('employee_id', $slip->employee_id)
            ->where('is_current', true)
            ->value('payment_method');

        if ($method === null) {
            $method = EmployeeSalary::query()
                ->where('employee_id', $slip->employee_id)
                ->orderByDesc('effective_from')
                ->value('payment_method');
        }

        if ($method === 'cash') {
            $cash = Account::query()
                ->where('code', '1001')
                ->orWhere(fn ($q) => $q->where('name', 'like', '%Cash in Hand%'))
                ->orderByRaw("CASE WHEN code = '1001' THEN 0 ELSE 1 END")
                ->first();

            if (! $cash) {
                $this->recordSkip($slip, 'This salary was paid in cash, but the chart of accounts has no Cash in Hand account, so the payment could not be posted.');
                Log::warning('Payroll cash payment posting skipped: no cash account', ['slip_id' => $slip->id]);

                return null;
            }

            return $cash;
        }

        $bank = Bank::where('is_active', true)->first();

        if (! $bank) {
            $this->recordSkip($slip, 'No active company bank, so the salary payment could not be posted.');
            Log::warning('Payroll payment posting skipped: no company bank', ['slip_id' => $slip->id]);

            return null;
        }

        $bankAccount = Account::find($bank->account_id);

        if (! $bankAccount) {
            $this->recordSkip($slip, sprintf('Bank "%s" is not linked to a ledger account, so the salary payment could not be posted.', $bank->name ?? $bank->id));
            Log::warning('Payroll payment posting skipped: bank account link missing', ['bank_id' => $bank->id]);

            return null;
        }

        return $bankAccount;
    }

    /** The slip is paid but unposted, and the reason belongs on the slip, not only in the log. */
    private function recordSkip(PayrollSlip $slip, string $reason): void
    {
        $slip->update(['payment_posting_error' => $reason]);
    }
}

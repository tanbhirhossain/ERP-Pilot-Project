<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Employee\Employee;

/**
 * A request to take money out of the fund.
 *
 * Three shapes, one table, because they follow the same lifecycle (pending → approved/rejected →
 * paid) and differ only in how much may be taken:
 *
 *   - `partial` — an advance against the employee's own contribution. Capped at that side; the
 *     employer's money is not the employee's to withdraw mid-service.
 *   - `final_settlement` — an employee leaving takes both sides. Capped at the total balance, and it
 *     closes the account when it is paid.
 *   - `loan_adjustment` — a payroll loan is being squared off against the fund. Recorded so the
 *     ledger explains why the balance dropped without a cash payout.
 *
 * `approved_amount` is separate from `requested_amount` because an approver routinely grants less
 * than was asked; the ledger must carry the figure that was actually decided, not the one requested.
 */
class PfWithdrawal extends Model
{
    protected $table = 'pf_withdrawals';

    protected $guarded = [];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'decided_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PfAccount::class, 'pf_account_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFinal(): bool
    {
        return $this->type === 'final_settlement';
    }

    /** The ceiling an approver may grant, given the side of the fund this type may draw on. */
    public function ceiling(): float
    {
        if ($this->account === null) {
            return 0.0;
        }

        return $this->isFinal()
            ? $this->account->totalBalance()
            : (float) $this->account->employee_balance;
    }
}

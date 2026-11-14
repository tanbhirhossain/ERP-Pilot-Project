<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HRM\Models\Employee\Employee;

/**
 * A provident-fund account: one per employee, and the unit every balance is reported in.
 *
 * `employee_balance` and `employer_balance` are maintained as running totals because the payroll
 * screen needs a number per row without aggregating a ledger for every page of results. They are
 * therefore *derived* values, and `recalculateBalances()` is the single way to restore them from the
 * ledger — a drift between the two is a bug, and this method is how it is proven to be none.
 */
class PfAccount extends Model
{
    protected $table = 'pf_accounts';

    protected $guarded = [];

    protected $casts = [
        'eligible_from' => 'date',
        'employee_balance' => 'decimal:2',
        'employer_balance' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PfPolicy::class, 'pf_policy_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(PfLedger::class)->orderBy('entry_date')->orderBy('id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(PfWithdrawal::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Total balance the employee could take out today: both sides, minus anything already debited. */
    public function totalBalance(): float
    {
        return round((float) $this->employee_balance + (float) $this->employer_balance, 2);
    }

    /**
     * Rebuild both cached balances from the ledger.
     *
     * Credits and debits are applied per side, so a withdrawal recorded against the employee side
     * cannot be paid out of the employer's money by accident. `settled` and `accrued` rows are both
     * counted: an accrued contribution is the employee's money the moment the month is closed, even
     * if the cash has not moved yet — that is what "accrual" means, and it is the model the user chose.
     */
    public function recalculateBalances(): self
    {
        $sums = $this->ledgers()
            ->selectRaw("
                coalesce(sum(case when entry_type = 'employee_contribution' then amount
                                  when entry_type in ('withdrawal','settlement') then -amount
                                  else 0 end), 0) as employee_side,
                coalesce(sum(case when entry_type = 'employer_contribution' then amount
                                  else 0 end), 0) as employer_side
            ")
            ->first();

        $this->update([
            'employee_balance' => round((float) ($sums->employee_side ?? 0), 2),
            'employer_balance' => round((float) ($sums->employer_side ?? 0), 2),
        ]);

        return $this;
    }
}

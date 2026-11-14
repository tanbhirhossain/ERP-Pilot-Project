<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F14 — one account's plan for one month.
 *
 * The `amount` is signed to the account's own nature (positive spend for a debit account, positive
 * income for a credit one) so it lines up with the nature-signed ledger movement the variance report
 * compares it against — no sign juggling at read time.
 */
class BudgetLine extends Model
{
    protected $table = 'acc_budget_lines';

    protected $fillable = ['budget_id', 'account_id', 'period', 'amount', 'notes'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}

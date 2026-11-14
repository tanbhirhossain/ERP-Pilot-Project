<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on a provident-fund account.
 *
 * The tab is a ledger, so the only rule that matters is that a balance can always be re-derived from
 * the entries rather than trusted from a cached column. Two properties make that possible here:
 *
 *   - `direction` is derived from `entry_type`, never stored. Contributions credit the account;
 *     withdrawals and settlements debit it. Storing it would create a second source of truth that
 *     can silently disagree with the first.
 *   - `status` separates an accrual (expense recognised, cash still in the company's hands) from a
 *     settlement (cash moved). The fund's liability is the sum of accrued entries; the fund's cash
 *     position is the sum of settled ones.
 */
class PfLedger extends Model
{
    protected $table = 'pf_ledgers';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
        'settled_at' => 'datetime',
    ];

    /** Entry types that take money out of the fund. Everything else pays in. */
    public const DEBIT_TYPES = ['withdrawal', 'settlement'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PfAccount::class, 'pf_account_id');
    }

    /** +1 for a credit, -1 for a debit — the sign a balance calculation applies to `amount`. */
    public function direction(): int
    {
        return in_array($this->entry_type, self::DEBIT_TYPES, true) ? -1 : 1;
    }

    /** The signed amount, which is what a running balance actually adds up. */
    public function signedAmount(): float
    {
        return round($this->direction() * (float) $this->amount, 2);
    }

    /** `2026-10` for an accrual dated 2026-10-31 — the period a contribution belongs to. */
    public static function periodFor(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}

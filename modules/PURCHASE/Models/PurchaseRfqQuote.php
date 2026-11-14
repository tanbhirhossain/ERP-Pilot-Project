<?php

namespace Modules\PURCHASE\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\PARTNER\Models\Partner;

/**
 * F18 — one vendor's answer to a round.
 *
 * `total_amount` is derived from the quote items and nothing else. It is stored so a comparison sorts
 * and paginates in SQL, and recomputed on every line write so it can never disagree with its own lines;
 * an award re-reads the lines and refuses a header that has drifted (the same "posted, but the total
 * moved" logic F22 uses for a stale price list).
 *
 * No VAT. A round compares pre-tax offers; tax and withholding belong to the bill, where the vendor's
 * actual status is known.
 */
class PurchaseRfqQuote extends Model
{
    protected $table = 'purchase_rfq_quotes';

    protected $fillable = [
        'rfq_id', 'vendor_id', 'quote_ref', 'quoted_on', 'valid_until',
        'status', 'total_amount', 'lead_time_days', 'notes', 'entered_by',
    ];

    protected $casts = [
        'quoted_on' => 'date',
        'valid_until' => 'date',
        'total_amount' => 'decimal:2',
        'lead_time_days' => 'integer',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfq::class, 'rfq_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'vendor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRfqQuoteItem::class, 'quote_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn';
    }

    /** Expired against its own validity, i.e. not awardable today. */
    public function isExpired(?CarbonInterface $today = null): bool
    {
        $until = $this->valid_until;

        return $until !== null && $until->startOfDay()->lt(($today ?? now())->startOfDay());
    }
}

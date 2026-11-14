<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F18 — a vendor's price for one requested line.
 *
 * Unique (quote, line): a vendor does not get to quote the same thing twice at two prices, and "which
 * one did we accept?" must have exactly one answer.
 */
class PurchaseRfqQuoteItem extends Model
{
    protected $table = 'purchase_rfq_quote_items';

    protected $fillable = ['quote_id', 'rfq_line_id', 'unit_price', 'qty', 'line_total', 'notes'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'qty' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqQuote::class, 'quote_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqLine::class, 'rfq_line_id');
    }
}

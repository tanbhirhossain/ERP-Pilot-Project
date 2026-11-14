<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F18 — the decision itself: this requested line was bought from this quote at this price.
 *
 * Kept as its own table, and unique per `rfq_line_id`, because "we awarded the round to Meghna Traders"
 * is not the atomic fact — the atomic fact is per line, and a round is routinely split between vendors.
 * The row survives the PO it created: if the PO is later cancelled, the audit trail still shows what was
 * decided and why, which is the question an auditor asks a procurement file.
 */
class PurchaseRfqAward extends Model
{
    protected $table = 'purchase_rfq_awards';

    protected $fillable = [
        'rfq_id', 'quote_id', 'rfq_line_id', 'requisition_item_id', 'unit_price', 'qty', 'order_id',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'qty' => 'decimal:2',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfq::class, 'rfq_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqQuote::class, 'quote_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqLine::class, 'rfq_line_id');
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'requisition_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}

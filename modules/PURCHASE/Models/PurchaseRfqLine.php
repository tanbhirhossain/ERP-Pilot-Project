<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F18 — what the round is asking for, in the market's terms rather than the warehouse's.
 *
 * Carried *by value* from the requisition (description + qty), not by reference: a requisition is an
 * internal document that keeps its wording, and an RFQ is the version you let a vendor see. The
 * nullable `requisition_item_id` is the thread back — the award needs it to mark progress, and an
 * RFQ raised without a requisition simply has none.
 */
class PurchaseRfqLine extends Model
{
    protected $table = 'purchase_rfq_lines';

    protected $fillable = ['rfq_id', 'requisition_item_id', 'description', 'qty', 'uom_id'];

    protected $casts = ['qty' => 'decimal:2'];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfq::class, 'rfq_id');
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'requisition_item_id');
    }

    public function quoteItems(): HasMany
    {
        return $this->hasMany(PurchaseRfqQuoteItem::class, 'rfq_line_id');
    }
}

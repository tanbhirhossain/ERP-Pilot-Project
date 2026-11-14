<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\INVENTORY\Models\Product;

/**
 * F18 — one requested line.
 *
 * `product_id` is nullable because a requisition is regularly about something the shop does not stock
 * yet ("a 5-tonne floor scale for the godown"); `description` is the required field and the product is
 * the refinement. A line without a product still gets quoted, compared and ordered — it just arrives as
 * a PO line with a description, exactly as the existing `purchase_order_items` allow.
 *
 * `qty_ordered` is the progress marker. It is written only by the award, never by an edit, which is
 * what keeps a second RFQ round from buying the same 40 bags twice.
 */
class PurchaseRequisitionItem extends Model
{
    protected $table = 'purchase_requisition_items';

    protected $fillable = [
        'requisition_id', 'product_id', 'description', 'uom_id',
        'qty', 'expected_unit_price', 'line_estimate', 'qty_ordered', 'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'expected_unit_price' => 'decimal:2',
        'line_estimate' => 'decimal:2',
        'qty_ordered' => 'decimal:2',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Still outstanding after every award so far. */
    public function remainingQty(): float
    {
        return max(0, (float) $this->qty - (float) $this->qty_ordered);
    }
}

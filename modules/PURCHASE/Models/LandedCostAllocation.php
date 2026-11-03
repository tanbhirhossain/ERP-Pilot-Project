<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostAllocation extends Model
{
    protected $table = 'purchase_landed_cost_allocations';
    protected $guarded = [];
    protected $casts = ['qty' => 'decimal:4', 'base_value' => 'decimal:2', 'allocated_cost' => 'decimal:2', 'landed_unit_cost' => 'decimal:4', 'new_unit_cost' => 'decimal:4'];
    public function landedCost(): BelongsTo { return $this->belongsTo(LandedCost::class, 'landed_cost_id'); }
    public function receiptItem(): BelongsTo { return $this->belongsTo(GoodsReceiptItem::class, 'receipt_item_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

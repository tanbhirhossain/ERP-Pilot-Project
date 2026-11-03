<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentItem extends Model
{
    protected $table = 'inventory_adjustment_items';
    protected $guarded = [];
    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2'];

    public function adjustment(): BelongsTo { return $this->belongsTo(Adjustment::class, 'adjustment_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class, 'location_id'); }
}

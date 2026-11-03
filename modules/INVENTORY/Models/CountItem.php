<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountItem extends Model
{
    protected $table = 'inventory_count_items';
    protected $guarded = [];
    protected $casts = ['system_qty' => 'decimal:4', 'counted_qty' => 'decimal:4', 'variance' => 'decimal:4', 'variance_cost' => 'decimal:2'];

    public function count(): BelongsTo { return $this->belongsTo(StockCount::class, 'count_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}

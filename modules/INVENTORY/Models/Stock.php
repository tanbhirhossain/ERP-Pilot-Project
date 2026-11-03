<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $table = 'inventory_stocks';
    protected $guarded = [];
    protected $casts = ['qty_on_hand' => 'decimal:4', 'qty_reserved' => 'decimal:4', 'qty_available' => 'decimal:4', 'avg_cost' => 'decimal:4', 'total_value' => 'decimal:2', 'last_movement_at' => 'datetime'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class, 'location_id'); }

    public function isLowStock(): bool
    {
        $reorder = (float) ($this->product->reorder_level ?? 0);
        return $reorder > 0 && (float) $this->qty_on_hand <= $reorder;
    }

    public function isOutOfStock(): bool { return (float) $this->qty_on_hand <= 0; }
}

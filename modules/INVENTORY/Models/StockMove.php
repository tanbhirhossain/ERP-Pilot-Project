<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockMove extends Model
{
    use LogsActivity;

    protected $table = 'inventory_stock_moves';

    protected $guarded = [];

    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2', 'moved_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function scopeForProduct($q, int $productId)
    {
        return $q->where('product_id', $productId);
    }

    public function scopeForWarehouse($q, int $warehouseId)
    {
        return $q->where('warehouse_id', $warehouseId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('inventory_move')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "StockMove {$e}");
    }
}

<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Kit extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'inventory_kits';
    protected $guarded = [];
    protected $casts = ['qty_to_produce' => 'decimal:4', 'qty_produced' => 'decimal:4', 'total_component_cost' => 'decimal:2', 'unit_cost' => 'decimal:4', 'completed_at' => 'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(KitItem::class, 'kit_id'); }
    public function isDraft(): bool { return $this->status === 'draft'; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('kit')->logAll()->logOnlyDirty(); }
}

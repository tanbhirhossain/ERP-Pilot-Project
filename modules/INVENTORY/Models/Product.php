<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Product extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'inventory_products';
    protected $guarded = [];
    protected $casts = [
        'cost_price' => 'decimal:2', 'selling_price' => 'decimal:2', 'mrp' => 'decimal:2', 'vat_rate' => 'decimal:2',
        'weight' => 'decimal:3', 'reorder_level' => 'decimal:2', 'reorder_qty' => 'decimal:2',
        'is_serialized' => 'boolean', 'is_batch_tracked' => 'boolean', 'is_active' => 'boolean', 'is_featured' => 'boolean',
        'shelf_life_days' => 'integer',
    ];

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory { return \Modules\INVENTORY\Database\Factories\ProductFactory::new(); }

    public function category(): BelongsTo { return $this->belongsTo(Category::class, 'category_id'); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class, 'brand_id'); }
    public function uom(): BelongsTo { return $this->belongsTo(Uom::class, 'uom_id'); }
    public function variants(): HasMany { return $this->hasMany(ProductVariant::class, 'product_id'); }
    public function suppliers(): HasMany { return $this->hasMany(ProductSupplier::class, 'product_id'); }
    public function bomComponents(): HasMany { return $this->hasMany(Bom::class, 'product_id'); }
    public function images(): HasMany { return $this->hasMany(ProductImage::class, 'product_id')->orderBy('sort_order'); }
    public function primaryImage(): BelongsTo { return $this->belongsTo(ProductImage::class, 'id', 'product_id'); }
    public function stocks(): HasMany { return $this->hasMany(Stock::class, 'product_id'); }
    public function moves(): HasMany { return $this->hasMany(StockMove::class, 'product_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(\Modules\CORE\Models\User::class, 'created_by'); }

    public function scopeActive($q) { return $q->where('is_active', true)->where('status', 'active'); }
    public function scopeStockable($q) { return $q->where('type', 'stockable'); }
    public function scopeLowStock($q) { return $q->whereHas('stocks', fn ($qq) => $qq->whereColumn('qty_on_hand', '<=', 'products.reorder_level')); }

    public function getStockStatusAttribute(): string
    {
        $onHand = $this->stocks->sum('qty_on_hand');
        if ($onHand <= 0) return 'out';
        if ($onHand <= (float) $this->reorder_level) return 'low';
        return 'in';
    }

    public function getTotalStockAttribute(): float
    {
        return (float) $this->stocks->sum('qty_on_hand');
    }

    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('inventory_product')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Product {$e}"); }
}

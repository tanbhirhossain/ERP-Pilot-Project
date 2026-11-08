<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $table = 'inventory_product_variants';

    protected $guarded = [];

    protected $casts = ['attributes' => 'array', 'cost_price' => 'decimal:2', 'selling_price' => 'decimal:2', 'mrp' => 'decimal:2', 'is_active' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'variant_id');
    }
}

<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitItem extends Model
{
    protected $table = 'inventory_kit_items';
    protected $guarded = [];
    protected $casts = ['required_qty' => 'decimal:4', 'consumed_qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'line_cost' => 'decimal:2', 'is_optional' => 'boolean'];
    public function kit(): BelongsTo { return $this->belongsTo(Kit::class, 'kit_id'); }
    public function component(): BelongsTo { return $this->belongsTo(Product::class, 'component_product_id'); }
}

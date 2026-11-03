<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleItem extends Model
{
    protected $table = 'pos_sale_items';
    protected $fillable = ['pos_sale_id', 'product_id', 'variant_id', 'product_name', 'sku', 'qty', 'unit_price', 'discount_pct', 'line_total', 'cost_price'];
    protected $casts = ['qty' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function sale(): BelongsTo { return $this->belongsTo(PosSale::class, 'pos_sale_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

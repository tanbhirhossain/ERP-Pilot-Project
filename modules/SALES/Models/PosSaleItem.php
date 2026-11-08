<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;
use Modules\INVENTORY\Models\Product;

class PosSaleItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['qty', 'unit_price', 'discount_pct', 'line_total'];
    }

    protected $table = 'pos_sale_items';

    protected $fillable = ['pos_sale_id', 'product_id', 'variant_id', 'product_name', 'sku', 'qty', 'unit_price', 'discount_pct', 'line_total', 'cost_price'];

    protected $casts = ['qty' => 'decimal:2', 'line_total' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'cost_price' => 'decimal:2'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

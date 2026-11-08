<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;
use Modules\INVENTORY\Models\Product;

class OrderItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['qty', 'qty_invoiced', 'qty_delivered', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total'];
    }

    protected $table = 'sales_order_items';

    protected $fillable = ['order_id', 'product_id', 'variant_id', 'product_name', 'sku', 'uom_id', 'qty', 'qty_invoiced', 'qty_delivered', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total'];

    protected $casts = ['qty' => 'decimal:2', 'qty_invoiced' => 'decimal:2', 'line_total' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function remainingQty(): float
    {
        return (float) $this->qty - (float) $this->qty_invoiced;
    }
}

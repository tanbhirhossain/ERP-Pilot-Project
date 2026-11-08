<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;
use Modules\INVENTORY\Models\Product;

class PurchaseOrderItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['qty', 'qty_received', 'qty_billed', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total'];
    }

    protected $table = 'purchase_order_items';

    protected $guarded = [];

    protected $casts = ['qty' => 'decimal:2', 'qty_received' => 'decimal:2', 'qty_billed' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'line_total' => 'decimal:2'];

    public $timestamps = true;

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

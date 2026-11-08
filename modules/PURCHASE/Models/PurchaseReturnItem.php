<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;
use Modules\INVENTORY\Models\Product;

class PurchaseReturnItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['qty', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total'];
    }

    protected $table = 'purchase_return_items';

    protected $guarded = [];

    protected $casts = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'line_total' => 'decimal:2'];

    public $timestamps = true;

    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

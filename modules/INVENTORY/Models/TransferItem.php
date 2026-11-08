<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;

class TransferItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['qty_requested', 'qty_shipped', 'qty_received', 'unit_cost'];
    }

    protected $table = 'inventory_transfer_items';

    protected $guarded = [];

    protected $casts = ['qty_requested' => 'decimal:4', 'qty_shipped' => 'decimal:4', 'qty_received' => 'decimal:4', 'unit_cost' => 'decimal:4'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}

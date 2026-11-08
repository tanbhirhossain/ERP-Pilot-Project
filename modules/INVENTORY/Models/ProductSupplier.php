<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PARTNER\Models\Partner;

class ProductSupplier extends Model
{
    protected $table = 'inventory_product_suppliers';

    protected $guarded = [];

    protected $casts = ['last_purchase_price' => 'decimal:2', 'lead_time_days' => 'integer', 'min_order_qty' => 'decimal:2', 'is_primary' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}

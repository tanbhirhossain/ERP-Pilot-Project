<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    protected $table = 'sales_delivery_items';
    protected $guarded = [];
    protected $casts = ['qty' => 'decimal:2', 'qty_delivered' => 'decimal:2'];
    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class, 'delivery_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class, 'order_item_id'); }
}

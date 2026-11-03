<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lot extends Model
{
    protected $table = 'inventory_lots';
    protected $guarded = [];
    protected $casts = ['manufacture_date' => 'date', 'expiry_date' => 'date', 'qty_received' => 'decimal:4', 'qty_remaining' => 'decimal:4', 'is_expired' => 'boolean'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'supplier_partner_id'); }
    public function isExpired(): bool { return $this->expiry_date && $this->expiry_date->isPast(); }
}

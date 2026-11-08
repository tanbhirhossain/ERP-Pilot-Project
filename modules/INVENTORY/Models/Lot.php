<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PARTNER\Models\Partner;

class Lot extends Model
{
    protected $table = 'inventory_lots';

    protected $guarded = [];

    protected $casts = ['manufacture_date' => 'date', 'expiry_date' => 'date', 'qty_received' => 'decimal:4', 'qty_remaining' => 'decimal:4', 'is_expired' => 'boolean', 'unit_cost' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_partner_id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }
}

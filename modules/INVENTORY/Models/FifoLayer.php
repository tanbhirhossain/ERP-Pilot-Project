<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FifoLayer extends Model
{
    protected $table = 'inventory_fifo_layers';
    protected $guarded = [];
    protected $casts = [
        'qty_in' => 'decimal:4', 'qty_remaining' => 'decimal:4',
        'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function lot(): BelongsTo { return $this->belongsTo(Lot::class, 'lot_id'); }
    public function stockMove(): BelongsTo { return $this->belongsTo(StockMove::class, 'stock_move_id'); }
}

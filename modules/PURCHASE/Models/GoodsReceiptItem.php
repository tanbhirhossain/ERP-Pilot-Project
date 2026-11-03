<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    protected $table = 'purchase_receipt_items';
    protected $guarded = [];
    protected $casts = ['qty'=>'decimal:2','unit_price'=>'decimal:2','discount_pct'=>'decimal:2','discount_amount'=>'decimal:2','vat_rate'=>'decimal:2','vat_amount'=>'decimal:2','line_total'=>'decimal:2'];
    public $timestamps = true;
    public function receipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class, 'receipt_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillItem extends Model
{
    protected $table = 'purchase_bill_items';
    protected $guarded = [];
    protected $casts = ['qty'=>'decimal:2','unit_price'=>'decimal:2','discount_pct'=>'decimal:2','discount_amount'=>'decimal:2','vat_rate'=>'decimal:2','vat_amount'=>'decimal:2','line_total'=>'decimal:2','cost_price'=>'decimal:2'];
    public $timestamps = true;
    public function bill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'bill_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

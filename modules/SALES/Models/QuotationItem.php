<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $table = 'sales_quotation_items';
    protected $fillable = ['quotation_id', 'product_id', 'variant_id', 'product_name', 'sku', 'uom_id', 'qty', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total', 'description', 'sort_order'];
    protected $casts = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function quotation(): BelongsTo { return $this->belongsTo(Quotation::class, 'quotation_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

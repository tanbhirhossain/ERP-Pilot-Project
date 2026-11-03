<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $table = 'sales_invoice_items';
    protected $fillable = ['invoice_id', 'order_item_id', 'product_id', 'variant_id', 'product_name', 'sku', 'uom_id', 'qty', 'unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total', 'cost_price'];
    protected $casts = ['qty' => 'decimal:2', 'line_total' => 'decimal:2', 'cost_price' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class, 'invoice_id'); }
    public function product(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Product::class, 'product_id'); }
}

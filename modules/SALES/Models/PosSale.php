<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosSale extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'pos_sales';
    protected $fillable = ['pos_no', 'session_id', 'invoice_id', 'customer_id', 'customer_name', 'warehouse_id', 'sale_date', 'subtotal', 'discount_amount', 'vat_amount', 'total_amount', 'paid_amount', 'change_amount', 'payment_method', 'cash_amount', 'bank_amount', 'bank_id', 'status', 'notes', 'created_by'];
    protected $casts = ['sale_date' => 'date', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function session(): BelongsTo { return $this->belongsTo(PosSession::class, 'session_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class, 'invoice_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'customer_id'); }
    public function items(): HasMany { return $this->hasMany(PosSaleItem::class, 'pos_sale_id'); }
}

<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;
    protected $table = 'sales_orders';
    protected $fillable = ['order_no', 'quotation_id', 'customer_id', 'warehouse_id', 'price_list_id', 'order_date', 'delivery_date', 'currency', 'subtotal', 'discount_amount', 'vat_amount', 'total_amount', 'invoiced_amount', 'status', 'payment_status', 'shipping_address', 'notes', 'created_by', 'confirmed_by', 'confirmed_at'];
    protected $casts = ['order_date' => 'date', 'delivery_date' => 'date', 'total_amount' => 'decimal:2', 'confirmed_at' => 'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'customer_id'); }
    public function quotation(): BelongsTo { return $this->belongsTo(Quotation::class, 'quotation_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class, 'order_id'); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class, 'order_id'); }
    public function deliveries(): HasMany { return $this->hasMany(Delivery::class, 'order_id'); }

    public function isConfirmable(): bool { return in_array($this->status, ['draft']); }
    public function isInvoicable(): bool { return in_array($this->status, ['confirmed', 'partially_invoiced']); }
    public function pendingQty(): float { return (float) $this->items->sum(fn($i) => (float)$i->qty - (float)$i->qty_invoiced); }

    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logOnly(['order_no', 'status', 'total_amount'])->logOnlyDirty()->useLogName('sales_orders'); }
}

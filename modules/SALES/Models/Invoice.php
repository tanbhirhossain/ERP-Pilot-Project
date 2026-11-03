<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;
    protected $table = 'sales_invoices';
    protected $fillable = ['invoice_no', 'order_id', 'quotation_id', 'customer_id', 'warehouse_id', 'journal_id', 'invoice_date', 'due_date', 'currency', 'subtotal', 'discount_amount', 'vat_amount', 'total_amount', 'paid_amount', 'status', 'payment_status', 'payment_method', 'bank_id', 'notes', 'created_by', 'posted_by', 'posted_at'];
    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'posted_at' => 'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'customer_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(\Modules\ACCOUNTS\Models\Journal::class, 'journal_id'); }
    public function items(): HasMany { return $this->hasMany(InvoiceItem::class, 'invoice_id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class, 'invoice_id'); }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isPosted(): bool { return in_array($this->status, ['posted', 'paid', 'partially_paid', 'overdue']); }
    public function balance(): float { return (float)$this->total_amount - (float)$this->paid_amount; }

    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logOnly(['invoice_no', 'status', 'total_amount'])->logOnlyDirty()->useLogName('sales_invoices'); }
}

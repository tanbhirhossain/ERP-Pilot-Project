<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Quotation extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;
    protected $table = 'sales_quotations';
    protected $fillable = ['quotation_no', 'customer_id', 'price_list_id', 'quotation_date', 'expiry_date', 'currency', 'subtotal', 'discount_amount', 'vat_amount', 'total_amount', 'status', 'notes', 'terms', 'converted_order_id', 'created_by', 'approved_by', 'approved_at'];
    protected $casts = ['quotation_date' => 'date', 'expiry_date' => 'date', 'subtotal' => 'decimal:2', 'total_amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'customer_id'); }
    public function items(): HasMany { return $this->hasMany(QuotationItem::class, 'quotation_id'); }
    public function convertedOrder(): BelongsTo { return $this->belongsTo(Order::class, 'converted_order_id'); }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isExpired(): bool { return $this->expiry_date && $this->expiry_date->isPast() && ! in_array($this->status, ['accepted', 'converted', 'rejected']); }
    public function scopeActive($q) { return $q->whereNotIn('status', ['rejected', 'expired']); }

    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logOnly(['quotation_no', 'status', 'total_amount'])->logOnlyDirty()->useLogName('sales_quotations'); }
}

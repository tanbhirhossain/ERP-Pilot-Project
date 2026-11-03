<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PurchaseOrder extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'purchase_orders';
    protected $guarded = [];
    protected $casts = [
        'order_date' => 'date', 'expected_date' => 'date',
        'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'received_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];
    public function vendor(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'vendor_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class, 'order_id'); }
    public function receipts(): HasMany { return $this->hasMany(GoodsReceipt::class, 'order_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(\Modules\CORE\Models\User::class, 'created_by'); }
    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return in_array($this->status, ['confirmed','partially_received','received']); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('purchase_order')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $e) => "PO {$e}"); }
}

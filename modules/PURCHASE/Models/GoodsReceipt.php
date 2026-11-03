<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GoodsReceipt extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'purchase_receipts';
    protected $guarded = [];
    protected $casts = ['receipt_date'=>'date','subtotal'=>'decimal:2','discount_amount'=>'decimal:2','vat_amount'=>'decimal:2','total_amount'=>'decimal:2','posted_at'=>'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'vendor_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(GoodsReceiptItem::class, 'receipt_id'); }
    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('purchase_receipt')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $e) => "GRN {$e}"); }
}

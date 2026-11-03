<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Delivery extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'sales_deliveries';
    protected $guarded = [];
    protected $casts = ['delivery_date' => 'date', 'shipped_at' => 'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class, 'invoice_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(\Modules\PARTNER\Models\Partner::class, 'customer_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(DeliveryItem::class, 'delivery_id'); }
    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isShipped(): bool { return in_array($this->status, ['shipped','delivered']); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('sales_delivery')->logAll()->logOnlyDirty(); }
}

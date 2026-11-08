<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ACCOUNTS\Models\Journal;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesReturn extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'sales_returns';

    protected $guarded = [];

    protected $casts = ['return_date' => 'date', 'subtotal' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'is_restocked' => 'boolean', 'approved_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'return_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('sales_return')->logAll()->logOnlyDirty();
    }
}

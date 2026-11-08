<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CORE\Models\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Adjustment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'inventory_adjustments';

    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'posted_at' => 'datetime'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdjustmentItem::class, 'adjustment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canApprove(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval'], true);
    }

    public function canPost(): bool
    {
        return $this->status === 'approved';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('inventory_adjustment')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Adjustment {$e}");
    }
}

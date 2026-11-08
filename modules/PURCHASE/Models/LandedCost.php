<?php

namespace Modules\PURCHASE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LandedCost extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'purchase_landed_costs';

    protected $guarded = [];

    protected $casts = ['total_cost' => 'decimal:2', 'posted_at' => 'datetime'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'receipt_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class, 'landed_cost_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class, 'landed_cost_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('landed_cost')->logAll()->logOnlyDirty();
    }
}

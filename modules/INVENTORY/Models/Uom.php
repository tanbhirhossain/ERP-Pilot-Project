<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Uom extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'inventory_uoms';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'conversion_factor' => 'decimal:4'];

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_uom_id');
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(self::class, 'base_uom_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('inventory_uom')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Uom {$e}");
    }
}

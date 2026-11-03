<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Location extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'inventory_locations';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'capacity' => 'decimal:2'];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function stocks(): HasMany { return $this->hasMany(Stock::class, 'location_id'); }
    public function scopeActive($q) { return $q->where('is_active', true); }
    public function getFullPathAttribute(): string { $parts = [$this->warehouse?->code ?? 'WH', $this->code]; if ($this->parent) $parts = [$this->warehouse?->code, $this->parent->code, $this->code]; return implode(' > ', array_filter($parts)); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('inventory_location')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Location {$e}"); }
}

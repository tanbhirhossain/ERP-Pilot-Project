<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PriceList extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class, 'price_list_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('price_list')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "PriceList {$e}");
    }
}

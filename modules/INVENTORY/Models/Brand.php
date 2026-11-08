<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Brand extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'inventory_brands';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('inventory_brand')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Brand {$e}");
    }
}

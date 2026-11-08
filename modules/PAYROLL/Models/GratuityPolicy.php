<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GratuityPolicy extends Model
{
    protected $table = 'gratuity_policies';

    protected $guarded = [];

    protected $casts = ['days_per_service_year' => 'decimal:2', 'minimum_service_years' => 'integer', 'is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

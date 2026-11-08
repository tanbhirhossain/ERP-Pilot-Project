<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FestivalBonusPolicy extends Model
{
    protected $table = 'festival_bonus_policies';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'minimum_service_months' => 'integer', 'bonuses_per_year' => 'integer', 'is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

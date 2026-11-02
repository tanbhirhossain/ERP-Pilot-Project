<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PfPolicy extends Model
{
    protected $table = 'pf_policies';
    protected $guarded = [];
    protected $casts = ['employee_contribution_percent' => 'decimal:2', 'employer_contribution_percent' => 'decimal:2', 'minimum_service_months' => 'integer', 'is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    public function accounts(): HasMany { return $this->hasMany(PfAccount::class); }
    public function scopeEffectiveOn(Builder $query, string $date): Builder { return $query->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date)); }
}

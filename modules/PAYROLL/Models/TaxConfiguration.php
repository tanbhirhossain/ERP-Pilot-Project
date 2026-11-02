<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxConfiguration extends Model
{
    protected $table = 'tax_configurations';
    protected $guarded = [];
    protected $casts = ['annual_exemption' => 'decimal:2', 'fixed_monthly_tax' => 'decimal:2', 'taxable_salary_percent' => 'decimal:2', 'is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    public function slabs(): HasMany { return $this->hasMany(TaxSlab::class)->orderBy('sort_order'); }
    public function scopeEffectiveOn(Builder $query, string $date): Builder { return $query->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date)); }
}

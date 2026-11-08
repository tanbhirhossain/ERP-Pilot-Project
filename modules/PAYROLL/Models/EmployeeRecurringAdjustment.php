<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRecurringAdjustment extends Model
{
    protected $table = 'employee_recurring_adjustments';

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean', 'amount' => 'decimal:2'];

    public function benefitTemplate(): BelongsTo
    {
        return $this->belongsTo(SalaryBenefitTemplate::class, 'benefit_template_id');
    }

    public function deductionTemplate(): BelongsTo
    {
        return $this->belongsTo(SalaryDeductionTemplate::class, 'deduction_template_id');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)->whereDate('effective_from', '<=', $date)->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}

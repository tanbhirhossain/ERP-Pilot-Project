<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryDeductionTemplate extends Model
{
    protected $table = 'salary_deduction_templates';
    protected $guarded = [];
    protected $casts = ['auto_apply' => 'boolean', 'is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'amount' => 'decimal:2'];
    public function scopeActive(Builder $query): Builder { return $query->where('is_active', true); }
    public function recurringAdjustments(): HasMany { return $this->hasMany(EmployeeRecurringAdjustment::class, 'deduction_template_id'); }
}

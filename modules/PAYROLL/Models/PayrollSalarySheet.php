<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSalarySheet extends Model
{
    protected $table = 'payroll_salary_sheets';

    protected $guarded = [];

    protected $casts = ['include_punishments' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'gross_total' => 'decimal:2', 'deduction_total' => 'decimal:2', 'net_total' => 'decimal:2'];

    public function slips(): HasMany
    {
        return $this->hasMany(PayrollSlip::class, 'salary_sheet_id');
    }

    public function cutlist(): BelongsTo
    {
        return $this->belongsTo(PayrollCutlist::class, 'payroll_cutlist_id');
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('pay_year', $year)->where('pay_month', $month);
    }
}

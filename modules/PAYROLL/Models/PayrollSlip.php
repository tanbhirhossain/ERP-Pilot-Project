<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSlip extends Model
{
    protected $table = 'payroll_slips';
    protected $guarded = [];
    protected $casts = ['breakdown_json' => 'array', 'basic_salary' => 'decimal:2', 'gross_salary' => 'decimal:2', 'total_benefits' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net_salary' => 'decimal:2', 'paid_at' => 'datetime'];
    public function salarySheet(): BelongsTo { return $this->belongsTo(PayrollSalarySheet::class, 'salary_sheet_id'); }
    public function loanInstallments(): HasMany { return $this->hasMany(PayrollLoanInstallment::class, 'payroll_slip_id'); }
    public function scopeHeld(Builder $query): Builder { return $query->where('payment_status', 'hold'); }
}

<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollLoanInstallment extends Model
{
    protected $table = 'payroll_loan_installments';

    protected $guarded = [];

    protected $casts = ['due_date' => 'date', 'paid_at' => 'datetime', 'amount' => 'decimal:2'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(PayrollLoan::class, 'payroll_loan_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }

    public function monthlyAllocation(): HasOne
    {
        return $this->hasOne(PayrollLoanMonthlyAllocation::class, 'payroll_loan_installment_id');
    }

    public function scopeDueOn(Builder $query, string $date): Builder
    {
        return $query->whereIn('status', ['scheduled', 'attached'])->whereDate('due_date', '<=', $date);
    }
}

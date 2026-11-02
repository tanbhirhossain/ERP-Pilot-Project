<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLoanMonthlyAllocation extends Model
{
    protected $table = 'payroll_loan_monthly_allocations';
    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_at' => 'datetime',
        'attached_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollLoanMonthlyBatch::class, 'batch_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(PayrollLoan::class, 'payroll_loan_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PayrollLoanInstallment::class, 'payroll_loan_installment_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }
}

<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HRM\Models\Employee\Employee;

class PayrollLoan extends Model
{
    protected $table = 'payroll_loans';
    protected $guarded = [];
    protected $casts = ['principal_amount' => 'decimal:2', 'installment_amount' => 'decimal:2', 'first_due_date' => 'date', 'approved_at' => 'datetime', 'approval_steps' => 'array'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class, 'employee_id', 'employee_id'); }
    public function installments(): HasMany { return $this->hasMany(PayrollLoanInstallment::class)->orderBy('installment_number'); }
    public function monthlyAllocations(): HasMany { return $this->hasMany(PayrollLoanMonthlyAllocation::class, 'payroll_loan_id'); }
    public function approvals(): HasMany { return $this->hasMany(PayrollLoanApproval::class)->orderBy('step_number'); }
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'approved_by'); }
    public function scopePending(Builder $query): Builder { return $query->where('status', 'pending'); }
}

<?php

namespace Modules\PAYROLL\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollLoanMonthlyBatch extends Model
{
    protected $table = 'payroll_loan_monthly_batches';

    protected $guarded = [];

    protected $casts = ['employee_ids' => 'array', 'locked_at' => 'datetime'];

    public function allocations(): HasMany
    {
        return $this->hasMany(PayrollLoanMonthlyAllocation::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}

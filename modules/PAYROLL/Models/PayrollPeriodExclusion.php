<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;

final class PayrollPeriodExclusion extends Model
{
    protected $table = 'payroll_period_exclusions';
    protected $guarded = [];
    protected $casts = ['pay_year' => 'integer', 'pay_month' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

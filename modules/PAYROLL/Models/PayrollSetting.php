<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'payroll_settings';

    protected $guarded = [];

    protected $casts = ['loan_approval_steps' => 'array', 'missing_punch_penalty_amount' => 'decimal:2', 'unpaid_leave_day_divisor' => 'decimal:2'];
}

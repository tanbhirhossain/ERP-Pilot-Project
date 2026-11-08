<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;

class PayrollCutlistItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['unpaid_leave_days', 'late_count', 'early_out_count', 'punishment_days', 'missing_punch_count', 'approved_overtime_minutes', 'approved_extra_duty_minutes', 'unpaid_leave_deduction', 'late_penalty_deduction', 'missing_punch_deduction', 'overtime_pay', 'extra_duty_pay'];
    }

    protected $table = 'payroll_cutlist_items';

    protected $guarded = [];

    protected $casts = ['unpaid_leave_days' => 'decimal:2', 'punishable' => 'boolean', 'unpaid_leave_deduction' => 'decimal:2', 'late_penalty_deduction' => 'decimal:2', 'missing_punch_deduction' => 'decimal:2', 'overtime_pay' => 'decimal:2', 'extra_duty_pay' => 'decimal:2', 'source_breakdown' => 'array'];

    public function cutlist(): BelongsTo
    {
        return $this->belongsTo(PayrollCutlist::class, 'payroll_cutlist_id');
    }
}

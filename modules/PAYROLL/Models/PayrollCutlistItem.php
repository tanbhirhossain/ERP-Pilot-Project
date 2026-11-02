<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCutlistItem extends Model
{
    protected $table = 'payroll_cutlist_items';
    protected $guarded = [];
    protected $casts = ['unpaid_leave_days' => 'decimal:2', 'punishable' => 'boolean', 'unpaid_leave_deduction' => 'decimal:2', 'late_penalty_deduction' => 'decimal:2', 'missing_punch_deduction' => 'decimal:2', 'overtime_pay' => 'decimal:2', 'extra_duty_pay' => 'decimal:2', 'source_breakdown' => 'array'];
    public function cutlist(): BelongsTo { return $this->belongsTo(PayrollCutlist::class, 'payroll_cutlist_id'); }
}

<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSheetReopen extends Model
{
    protected $table = 'payroll_sheet_reopens';

    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(PayrollSalarySheet::class, 'salary_sheet_id');
    }
}

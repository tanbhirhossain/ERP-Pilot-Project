<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSettlement extends Model
{
    protected $table = 'payroll_settlements';
    protected $guarded = [];
    protected $casts = ['settlement_date' => 'date', 'last_working_date' => 'date', 'gross_due' => 'decimal:2', 'gratuity_amount' => 'decimal:2', 'leave_encashment' => 'decimal:2', 'other_benefits' => 'decimal:2', 'deductions' => 'decimal:2', 'net_settlement' => 'decimal:2', 'breakdown_json' => 'array', 'approved_at' => 'datetime'];
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'approved_by'); }
}

<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryIncrement extends Model
{
    protected $table = 'salary_increments';
    protected $guarded = [];
    protected $casts = ['effective_date' => 'date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'increment_value' => 'decimal:2', 'previous_basic' => 'decimal:2', 'new_basic' => 'decimal:2', 'previous_gross' => 'decimal:2', 'new_gross' => 'decimal:2'];
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'approved_by'); }
    public function requester(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'requested_by'); }
    public function rejector(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'rejected_by'); }
}

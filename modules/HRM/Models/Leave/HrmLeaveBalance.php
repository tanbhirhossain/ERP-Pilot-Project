<?php

namespace Modules\HRM\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrmLeaveBalance extends Model
{
    protected $table = 'hrm_leave_balances';
    protected $guarded = [];
    protected $casts = [
        'opening_balance' => 'decimal:2', 'entitlement' => 'decimal:2', 'carry_forward' => 'decimal:2',
        'availed' => 'decimal:2', 'pending' => 'decimal:2', 'encashed' => 'decimal:2', 'lapsed' => 'decimal:2',
        'available' => 'decimal:2', 'is_closed' => 'boolean', 'closed_at' => 'datetime',
    ];

    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class, 'leave_type_id'); }
    public function isClosed(): bool { return (bool)$this->is_closed; }
}

<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAttendanceDutyDecision extends Model
{
    protected $table = 'payroll_attendance_duty_decisions';
    protected $guarded = [];
    protected $casts = [
        'duty_date' => 'date',
        'attendance_source_hours' => 'decimal:2',
        'requested_hours' => 'decimal:2',
        'approved_hours' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(\Modules\HRM\Models\Roster\RosterOvertime::class, 'roster_overtime_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }
}

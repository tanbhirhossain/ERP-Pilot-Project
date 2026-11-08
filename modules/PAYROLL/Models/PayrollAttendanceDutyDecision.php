<?php

namespace Modules\PAYROLL\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Roster\RosterOvertime;

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
        return $this->belongsTo(RosterOvertime::class, 'roster_overtime_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

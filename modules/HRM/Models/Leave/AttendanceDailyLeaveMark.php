<?php

namespace Modules\HRM\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance day that an approved leave application overwrote.
 *
 * Approving leave writes "On Leave" onto the attendance calendar. Before this table existed nothing
 * recorded which days that had touched or what they said before, so cancelling or rejecting a leave
 * could not put the calendar back — the employee stayed "On Leave" for ever.
 */
class AttendanceDailyLeaveMark extends Model
{
    protected $table = 'hrm_attendance_leave_marks';

    protected $guarded = [];

    protected $casts = ['half_day' => 'boolean'];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}

<?php

namespace Modules\HRM\Models\Attendance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\AttendancePairFactory;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Roster\RosterShift;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendancePair extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = ['duty_date' => 'date', 'punch_in' => 'datetime', 'punch_out' => 'datetime', 'worked_hours' => 'decimal:2'];

    protected static function newFactory()
    {
        return AttendancePairFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'shift_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_attendance_pair')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $event): string => class_basename($this)." $event");
    }
}

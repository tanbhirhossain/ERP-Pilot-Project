<?php

namespace Modules\HRM\Models\Attendance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\AttendanceLogFactory;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendanceLog extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = ['punch_time' => 'datetime', 'is_processed' => 'boolean'];

    protected static function newFactory()
    {
        return AttendanceLogFactory::new();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function deviceUser(): BelongsTo
    {
        return $this->belongsTo(DeviceUser::class, 'device_user_id', 'device_user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_attendance_log')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $event): string => class_basename($this)." $event");
    }
}

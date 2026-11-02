<?php
namespace Modules\HRM\Models\Attendance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AttendanceLog extends Model {
    use HasFactory, LogsActivity;
    protected $guarded = [];
    protected $casts = ['punch_time'=>'datetime','is_processed'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\AttendanceLogFactory::new(); }
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function deviceUser(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\DeviceUser::class, 'device_user_id','device_user_id'); }
    public function device(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\BiometricDevice::class, 'device_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_attendance_log')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}

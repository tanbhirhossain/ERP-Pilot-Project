<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class BiometricDevice extends Model {
    use HasFactory, LogsActivity;
    protected $guarded = [];
    protected $casts = ['is_enabled'=>'boolean','auto_sync_enabled'=>'boolean','last_sync_at'=>'datetime','is_active'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\BiometricDeviceFactory::new(); }
    public function syncSetting(): HasOne { return $this->hasOne(AttendanceSyncSetting::class, 'device_id'); }
    public function syncLogs(): HasMany { return $this->hasMany(AttendanceSyncLog::class, 'device_id'); }
    public function attendanceLogs(): HasMany { return $this->hasMany(\Modules\HRM\Models\Attendance\AttendanceLog::class, 'device_id'); }
    public function deviceUsers(): HasMany { return $this->hasMany(DeviceUser::class, 'device_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_device')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}

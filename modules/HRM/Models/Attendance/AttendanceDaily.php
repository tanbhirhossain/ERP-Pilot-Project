<?php
namespace Modules\HRM\Models\Attendance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AttendanceDaily extends Model {
    use HasFactory, LogsActivity;
    protected $guarded = [];
    protected $table = 'attendance_dailies';
    protected $casts = ['duty_date'=>'date','primary_punch_in'=>'datetime','primary_punch_out'=>'datetime','primary_worked_hours'=>'decimal:2','total_overtime_hours'=>'decimal:2','grand_total_hours'=>'decimal:2'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\AttendanceDailyFactory::new(); }
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Roster\RosterShift::class, 'primary_shift_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_attendance_daily')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}

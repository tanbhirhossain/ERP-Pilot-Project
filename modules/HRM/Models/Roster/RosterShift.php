<?php
namespace Modules\HRM\Models\Roster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RosterShift extends Model {
    use HasFactory, LogsActivity;
    protected $guarded = [];
    protected $casts = ['crosses_midnight'=>'boolean','max_duration_hours'=>'integer','is_fallback'=>'boolean','buffer_before'=>'integer','buffer_after'=>'integer'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\RosterShiftFactory::new(); }
    public function duties(): HasMany { return $this->hasMany(RosterDuty::class, 'shift_id'); }
    public function pairs(): HasMany { return $this->hasMany(\Modules\HRM\Models\Attendance\AttendancePair::class, 'shift_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_roster_shift')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}

<?php
namespace Modules\HRM\Models\Roster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RosterDuty extends Model {
    use HasFactory, LogsActivity;
    protected $guarded = [];
    protected $casts = ['roster_date'=>'date','custom_start_datetime'=>'datetime','custom_end_datetime'=>'datetime'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\RosterDutyFactory::new(); }
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(RosterShift::class, 'shift_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_roster_duty')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event): string => class_basename($this) . " $event");
    }
}

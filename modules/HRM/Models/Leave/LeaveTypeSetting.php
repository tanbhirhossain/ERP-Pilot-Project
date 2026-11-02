<?php
namespace Modules\HRM\Models\Leave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
class LeaveTypeSetting extends Model {
    use LogsActivity;
    protected $table='leave_type_settings'; protected $guarded=[];
    protected $casts=['working_days'=>'array','approval_steps'=>'array'];
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_leave_type_setting')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event)=>class_basename($this).' '.$event); }
}

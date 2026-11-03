<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class AttendanceRegularization extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'hrm_attendance_regularizations';
    protected $guarded = [];
    protected $casts = ['duty_date'=>'date','requested_in_time'=>'datetime:H:i','requested_out_time'=>'datetime:H:i','reviewed_at'=>'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Roster\RosterShift::class, 'assigned_shift_id'); }
    public function isPending(): bool { return $this->status === 'Pending'; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_regularization')->logAll()->logOnlyDirty(); }
}

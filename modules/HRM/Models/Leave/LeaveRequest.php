<?php
namespace Modules\HRM\Models\Leave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class LeaveRequest extends Model {
    use HasFactory, LogsActivity;
    protected $table = 'leave_requests';
    protected $guarded = [];
    protected $casts = ['from_date'=>'date','to_date'=>'date','days'=>'decimal:2','is_half_day'=>'boolean','approved_at'=>'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class,'leave_type_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class,'approved_by'); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_leave_request')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $e) => class_basename($this).' '.$e); }
}

<?php
namespace Modules\HRM\Models\Leave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class LeaveType extends Model {
    use HasFactory, LogsActivity;
    protected $table = 'leave_types';
    protected $guarded = [];
    protected $casts = ['days_per_year'=>'integer','is_paid'=>'boolean','is_active'=>'boolean'];
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_leave_type')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $e) => class_basename($this).' '.$e); }
}

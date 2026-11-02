<?php
namespace Modules\HRM\Models\Informed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class InformedRequest extends Model {
    use HasFactory, LogsActivity;
    protected $table = 'informed_requests';
    protected $guarded = [];
    protected $casts = ['informed_date'=>'date','expected_time'=>'string','approved_at'=>'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class,'employee_id','employee_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class,'approved_by'); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_informed')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $e) => class_basename($this).' '.$e); }
}

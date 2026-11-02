<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
class Holiday extends Model {
    use LogsActivity;
    protected $table='hrm_holidays';
    protected $guarded=[];
    protected $casts=['holiday_date'=>'date'];
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_holiday')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn(string $event)=>class_basename($this).' '.$event); }
}

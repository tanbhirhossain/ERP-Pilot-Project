<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ClearanceTask extends Model
{
    use LogsActivity;
    protected $table = 'hrm_clearance_tasks';
    protected $guarded = [];
    protected $casts = ['cleared_at'=>'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_clearance')->logAll()->logOnlyDirty(); }
}

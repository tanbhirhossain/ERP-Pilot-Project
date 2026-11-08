<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ClearanceTask extends Model
{
    use LogsActivity;

    protected $table = 'hrm_clearance_tasks';

    protected $guarded = [];

    protected $casts = ['cleared_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /** Who signed this task off — the exit-clearance audit trail. */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_clearance')->logAll()->logOnlyDirty();
    }
}

<?php

namespace Modules\HRM\Models\Roster;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RosterOvertime extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'roster_overtimes';

    protected $guarded = [];

    protected $casts = ['overtime_date' => 'date', 'start_time' => 'string', 'end_time' => 'string', 'hours' => 'decimal:2', 'approved_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'shift_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_roster_overtime')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => class_basename($this).' '.$e);
    }
}

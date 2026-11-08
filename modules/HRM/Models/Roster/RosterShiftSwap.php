<?php

namespace Modules\HRM\Models\Roster;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\RosterShiftSwapFactory;
use Modules\HRM\Models\Employee\Employee;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RosterShiftSwap extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = ['swap_date' => 'date'];

    protected $table = 'roster_shift_swaps';

    protected static function newFactory()
    {
        return RosterShiftSwapFactory::new();
    }

    public function duty(): BelongsTo
    {
        return $this->belongsTo(RosterDuty::class, 'roster_duty_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id', 'employee_id');
    }

    public function requestedShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'requested_shift_id');
    }

    public function targetShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'target_shift_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_roster_swap')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e): string => class_basename($this)." $e");
    }
}

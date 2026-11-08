<?php

namespace Modules\HRM\Models\Roster;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HRM\Database\Factories\RosterShiftFactory;
use Modules\HRM\Models\Attendance\AttendancePair;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RosterShift extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = ['crosses_midnight' => 'boolean', 'max_duration_hours' => 'integer', 'is_fallback' => 'boolean', 'buffer_before' => 'integer', 'buffer_after' => 'integer'];

    protected static function newFactory()
    {
        return RosterShiftFactory::new();
    }

    public function duties(): HasMany
    {
        return $this->hasMany(RosterDuty::class, 'shift_id');
    }

    public function pairs(): HasMany
    {
        return $this->hasMany(AttendancePair::class, 'shift_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_roster_shift')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $event): string => class_basename($this)." $event");
    }
}

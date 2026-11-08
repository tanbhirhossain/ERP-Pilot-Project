<?php

namespace Modules\HRM\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkCalendar extends Model
{
    protected $table = 'hrm_work_calendars';

    protected $guarded = [];

    protected $casts = ['weekend_days' => 'array', 'working_hours' => 'array', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function isWeekend(Carbon $date): bool
    {
        $days = $this->weekend_days ?? [5, 6]; // Fri,Sat default BD

        return in_array($date->dayOfWeek, $days, true);
    }
}

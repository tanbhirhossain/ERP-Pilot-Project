<?php

namespace Modules\HRM\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Roster\RosterShift;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ShiftAllowance extends Model
{
    use LogsActivity;

    protected $table = 'hrm_shift_allowances';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'shift_id');
    }

    public function isEffective(?string $date = null): bool
    {
        $d = $date ? Carbon::parse($date) : now();
        if ($this->effective_from && $d->lt($this->effective_from)) {
            return false;
        }
        if ($this->effective_to && $d->gt($this->effective_to)) {
            return false;
        }

        return (bool) $this->is_active;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_shift_allowance')->logAll()->logOnlyDirty();
    }
}

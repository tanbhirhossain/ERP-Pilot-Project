<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Roster\RosterShift;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendanceRegularization extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'hrm_attendance_regularizations';

    protected $guarded = [];

    protected $casts = ['duty_date' => 'date', 'requested_in_time' => 'datetime:H:i', 'requested_out_time' => 'datetime:H:i', 'reviewed_at' => 'datetime'];

    /**
     * The kinds of correction a day can be asked for.
     *
     * This is the single source of truth for the list, and it matches the `enum` the migration wrote into
     * the schema (`2026_11_03_000072_create_attendance_hardening`). That mattered: the form validator used
     * its own four values, which rejected five of the eight the picker offered — and accepted `Short Hours`,
     * which the column cannot store. A list duplicated between a rule and a `<select>` drifts, and the
     * failure looks like a dead button rather than a bug.
     */
    public const TYPES = [
        'Missing In', 'Missing Out', 'Wrong Shift', 'Late Correction', 'Early Out Correction',
        'On Duty', 'Work From Home', 'Remote Punch',
    ];

    /** What a reviewer may decide. Anything else is not a review, it is a new state nobody modelled. */
    public const DECISIONS = ['Approved', 'Rejected'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'assigned_shift_id');
    }

    /**
     * The user who approved or rejected the request (`reviewed_by`, FK to `users`, nullOnDelete — see
     * migration 2026_11_03_000072_create_attendance_hardening).
     *
     * `RegularizationController::index()` has eager-loaded `reviewer:id,name` since the review desk was
     * built, but the relation was never declared on this model, so the queue list 500'd with
     * `RelationNotFoundException: Call to undefined relationship [reviewer]` the moment the page was
     * opened. The column existed; the accessor did not.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('hrm_regularization')->logAll()->logOnlyDirty();
    }
}

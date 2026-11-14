<?php

namespace Modules\HRM\Support\Attendance;

use Illuminate\Database\Eloquent\Builder;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Services\Attendance\AttendanceRepairService;

/**
 * "These days, for these employees" — the one place a repair range is turned into SQL.
 *
 * It exists because a repair touches three tables whose date columns are not alike, and the repair's
 * {@see AttendanceRepairService} reads two of them through the query
 * builder (whole rows, verbatim) while the rest goes through models. When the same rule is written twice in
 * two syntaxes, they drift: the pairing repair used `whereDate` in its model scopes and
 * `whereBetween('duty_date', [$from.' 00:00:00', $to.' 23:59:59'])` in its raw reads, and because
 * `attendance_dailies.duty_date` is a `date` column, `'2026-10-01' >= '2026-10-01 00:00:00'` is false — the
 * first day of every requested range was cleared but never snapshotted, so punch-less Off Day / Holiday /
 * On Leave / approved-regularization rows on the 1st were downgraded to a synthetic `Absent`. That is exactly
 * the loss this repair was written to stop, surviving inside the fix for one day a month.
 *
 * So the range lives here, once, with the reason for each column's shape next to it.
 */
final class AttendanceRepairScope
{
    /**
     * @param  array<int, string>|null  $employeeIds  null = every employee
     */
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly ?array $employeeIds = null,
    ) {}

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    /** A `null` employee list is the global scope, not "nobody" — callers must not treat it as empty. */
    public function limitsEmployees(): bool
    {
        return $this->employeeIds !== null;
    }

    /**
     * @return array<int, string>|null
     */
    public function employeeIds(): ?array
    {
        return $this->employeeIds;
    }

    /**
     * Whole-day bounds on a `date` column (`attendance_dailies.duty_date`, `attendance_pairs.duty_date`).
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyDateRange($query)
    {
        return $query
            ->whereDate('duty_date', '>=', $this->from)
            ->whereDate('duty_date', '<=', $this->to)
            ->when($this->employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $this->employeeIds));
    }

    /**
     * Full-day bounds on a `dateTime` column (`attendance_logs.punch_time`), where the time of day is real
     * data and has to be included: a bare `whereBetween('punch_time', [$from, $to])` would drop every punch
     * after midnight on the range's last day.
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyPunchWindow($query)
    {
        return $query->whereBetween('punch_time', [$this->windowStart(), $this->windowEnd()]);
    }

    /** For collaborators whose own signature takes datetimes, e.g. `AttendanceProcessingService::processPending()`. */
    public function windowStart(): string
    {
        return $this->from.' 00:00:00';
    }

    public function windowEnd(): string
    {
        return $this->to.' 23:59:59';
    }

    /**
     * @return Builder<AttendanceDaily>
     */
    public function dailies(): Builder
    {
        return $this->applyDateRange(AttendanceDaily::query());
    }

    /**
     * @return Builder<AttendancePair>
     */
    public function pairs(): Builder
    {
        return $this->applyDateRange(AttendancePair::query());
    }
}

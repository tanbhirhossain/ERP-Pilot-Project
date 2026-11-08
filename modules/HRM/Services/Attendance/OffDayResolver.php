<?php

namespace Modules\HRM\Services\Attendance;

use Carbon\Carbon;
use Modules\HRM\Models\Roster\RosterDuty;

/**
 * Single source of truth for "is this calendar date an off day for this
 * employee?".
 *
 * This logic used to be copy-pasted into four places — the day reconciler,
 * `AttendanceDailyRepository::autoSyncOvertime()`,
 * `AttendanceDailyRepository::isOffOrHoliday()` and `RosterOvertimeService` —
 * each with its own slightly different guard. That duplication is why the
 * fallback off day silently stopped working: every copy required that NO roster
 * duty exist, and bulk rostering creates one for every calendar day.
 *
 * Resolution order:
 *   1. A roster duty explicitly marked 'Off' is authoritative. Always.
 *   2. The employee's fallback off day, matched on Carbon weekday number.
 *   3. Whether a merely-existing roster duty suppresses (2) is a deployment
 *      decision — see config('hrm.attendance.roster_overrides_fallback_off_day').
 *
 * Deliberately pure and stateless: callers pre-load the fallback-day map so a
 * bulk run issues one query, not one per employee per date.
 */
final class OffDayResolver
{
    /**
     * @param  int|null  $fallbackDayOfWeek  Carbon weekday (0=Sunday .. 6=Saturday), or null when the employee has none
     * @param  string|Carbon  $date  the calendar date being classified
     */
    public function isOffDay(?RosterDuty $duty, ?int $fallbackDayOfWeek, string|Carbon $date): bool
    {
        // 1. An explicit roster Off always wins — HR said so directly.
        if ($duty?->status === 'Off') {
            return true;
        }

        // No fallback configured: the roster is the only source of off days.
        if ($fallbackDayOfWeek === null) {
            return false;
        }

        // 2. Does this date fall on the employee's contracted off day?
        if ($this->dayOfWeek($date) !== (int) $fallbackDayOfWeek) {
            return false;
        }

        // No roster duty at all — the original, undisputed case.
        if ($duty === null) {
            return true;
        }

        /*
         * 3. A duty exists and the date is the fallback off day.
         *
         * bulkAssign() rosters every calendar day indiscriminately, so the duty
         * alone is not evidence the employee was expected to work. Unless the
         * deployment has asked for strict roster authority, the contracted off
         * day wins — otherwise a bulk-rostered month marks every Friday (or
         * whichever day is off) as Absent.
         */
        return ! (bool) config('hrm.attendance.roster_overrides_fallback_off_day', false);
    }

    /**
     * Bulk-friendly variant for callers that already hold a map of
     * employee_id => fallback weekday.
     *
     * @param  array<string, int>  $fallbackOffDays
     */
    public function isOffDayFor(?RosterDuty $duty, string $employeeId, string|Carbon $date, array $fallbackOffDays): bool
    {
        $fallback = $fallbackOffDays[$employeeId] ?? null;

        return $this->isOffDay($duty, $fallback === null ? null : (int) $fallback, $date);
    }

    private function dayOfWeek(string|Carbon $date): int
    {
        return $date instanceof Carbon ? $date->dayOfWeek : Carbon::parse($date)->dayOfWeek;
    }
}

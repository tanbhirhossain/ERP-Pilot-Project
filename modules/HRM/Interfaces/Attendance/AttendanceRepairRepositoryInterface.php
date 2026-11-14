<?php

namespace Modules\HRM\Interfaces\Attendance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Services\Attendance\AttendanceRepairService;
use Modules\HRM\Support\Attendance\AttendanceRepairScope;

/**
 * The persistence half of a pairing repair.
 *
 * The repair's policy is "clear a range, re-derive it, and put back whatever the re-derivation cannot
 * produce". Producing that needs three things Eloquent is deliberately bad at: reading whole rows as written
 * (a snapshot that has to survive must not be filtered through casts and mutators), writing them back in
 * bulk without firing thousands of model events, and one atomic clear across three tables. So those live
 * here, behind this interface, and {@see AttendanceRepairService} decides
 * what to do with the rows instead of how to fetch them.
 *
 * Every method takes an {@see AttendanceRepairScope}, which is what keeps the range predicate single-sourced.
 */
interface AttendanceRepairRepositoryInterface
{
    /**
     * How much of the range a repair is about to erase — for the progress step and the operator's report.
     *
     * @return array{pairs: int, dailies: int, logs: int}
     */
    public function scopeCounts(AttendanceRepairScope $scope): array;

    /**
     * Delete the range's pairs and dailies and mark its logs un-processed, in one transaction: a clear that
     * commits on its own is how a killed request used to leave a month half-rebuilt.
     */
    public function clear(AttendanceRepairScope $scope): void;

    /**
     * The rows no punch can resurrect, taken before the clear: punch-less days whose status the roster may
     * or may not reproduce, plus corrected days the reconciler never produces.
     *
     * @param  array<int, string>  $statuses
     * @return Collection<int, object> one raw row per employee-day, every column as stored
     */
    public function unreproducibleRows(AttendanceRepairScope $scope, array $statuses): Collection;

    /**
     * What the range looks like now, keyed `employee_id|Y-m-d`, so a caller can compare it against a snapshot
     * without knowing that `duty_date` is a `date` column.
     *
     * @return array<string, object>
     */
    public function rowIndex(AttendanceRepairScope $scope): array;

    /**
     * Re-add snapshot rows, skipping any employee-day the rebuild has meanwhile produced.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int rows inserted
     */
    public function insertRows(array $rows): int;

    /**
     * Overwrite rows the rebuild derived from nothing (a synthetic punch-less `Absent`) with the snapshot.
     *
     * @param  array<int, array<string, mixed>>  $rowsById  keyed by primary key
     * @return int rows updated
     */
    public function replaceRows(array $rowsById): int;

    /**
     * The logs this scope still has to pair. Employee-limited scopes must also pick up punches that are not
     * yet attributed to an employee, which is why the identifier indirection lives here and not in the caller.
     *
     * @return Builder<AttendanceLog>
     */
    public function logsToReprocess(AttendanceRepairScope $scope): Builder;
}

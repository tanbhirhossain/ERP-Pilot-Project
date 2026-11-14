<?php

namespace Modules\HRM\Repositories\Attendance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Interfaces\Attendance\AttendanceRepairRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Support\Attendance\AttendanceRepairScope;

/**
 * The repair's reads and writes, as they have to be done.
 *
 * Three deliberate choices, all of them the reason this class exists instead of the service just using
 * `AttendanceDaily` everywhere:
 *
 *  - **Snapshot rows come back raw.** `attendance_dailies` has columns the model does not cast and
 *    attributes it would happily default (`is_regularized`, `remarks`, the punch datetimes). A safety net
 *    that has to re-insert a row *exactly as stored* reads and writes columns, not attributes, so the base
 *    query builder is used for those three operations. Returning Eloquent models here would put the casting
 *    rules back in the caller's hands — the whole reason the first version lost a day.
 *  - **Bulk writes, no events.** A month-sized scope is ~1 000 employees × 31 days. Restoring rows one
 *    `save()` at a time is tens of thousands of queries and model events for work that is, by definition,
 *    mechanical; chunked `insertOrIgnore` and a keyed `update` keep a global repair at well under a second
 *    (measured: 786 ms on the 998-employee development database).
 *  - **The clear is one transaction.** Pairs, dailies and the `is_processed` reset either all happen or none
 *    do, because the rebuild that follows reads exactly that state.
 *
 * @see AttendanceRepairRepositoryInterface
 */
class AttendanceRepairRepository implements AttendanceRepairRepositoryInterface
{
    /** Rows per statement when putting the snapshot back: big enough to batch, small enough to retry cheaply. */
    private const INSERT_CHUNK = 200;

    public function scopeCounts(AttendanceRepairScope $scope): array
    {
        return [
            'pairs' => (clone $scope->pairs())->count(),
            'dailies' => (clone $scope->dailies())->count(),
            'logs' => (clone $this->logsToReprocess($scope))->count(),
        ];
    }

    public function clear(AttendanceRepairScope $scope): void
    {
        DB::transaction(function () use ($scope): void {
            $scope->pairs()->delete();
            $scope->dailies()->delete();
            // Un-processed on purpose: the pairing stage only picks up pending logs, so a rebuild without
            // this reset would re-pair nothing.
            $this->logsToReprocess($scope)->update(['is_processed' => false]);
        });
    }

    public function unreproducibleRows(AttendanceRepairScope $scope, array $statuses): Collection
    {
        return $scope->applyDateRange(DB::table('attendance_dailies'))
            ->whereNull('primary_punch_in')
            ->whereNull('primary_punch_out')
            ->where(function ($query) use ($statuses): void {
                $query->whereIn('status', $statuses)->orWhere('is_regularized', true);
            })
            ->get();
    }

    public function rowIndex(AttendanceRepairScope $scope): array
    {
        return $scope->applyDateRange(DB::table('attendance_dailies'))
            ->get()
            ->keyBy(fn (object $row) => $row->employee_id.'|'.substr((string) $row->duty_date, 0, 10))
            ->all();
    }

    public function insertRows(array $rows): int
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            // insertOrIgnore: a daily that appeared between the caller's snapshot comparison and this write
            // is the better row (it came from the rebuild) and must not be overwritten. The retry count is
            // for the sqlite "database is locked" case, which a nightly pipeline running alongside will do.
            DB::transaction(fn () => AttendanceDaily::query()->insertOrIgnore($chunk), 3);
        }

        return count($rows);
    }

    public function replaceRows(array $rowsById): int
    {
        foreach ($rowsById as $id => $attributes) {
            // where('id'), not whereKey(): the snapshot's own `employee_id`/`duty_date` are deliberately not
            // part of the update — the row keeps the identity the pipeline gave it.
            DB::table('attendance_dailies')->where('id', (int) $id)->update($attributes);
        }

        return count($rowsById);
    }

    public function logsToReprocess(AttendanceRepairScope $scope): Builder
    {
        $query = $scope->applyPunchWindow(AttendanceLog::query());
        $employeeIds = $scope->employeeIds();

        if ($employeeIds === null) {
            return $query;
        }

        $deviceUserIds = $this->punchIdentifiers($employeeIds);

        return $query->where(function ($scope2) use ($employeeIds, $deviceUserIds): void {
            $scope2->whereIn('employee_id', $employeeIds);

            if ($deviceUserIds !== []) {
                $scope2->orWhereIn('device_user_id', $deviceUserIds);
            }
        });
    }

    /**
     * Ids the biometric logs may be filed under before they are attributed: the device user ids mapped to
     * these employees, plus any employee who carries a biometric id directly.
     *
     * @param  array<int, string>  $employeeIds
     * @return array<int, string>
     */
    private function punchIdentifiers(array $employeeIds): array
    {
        return DeviceUser::query()->whereIn('employee_id', $employeeIds)->pluck('device_user_id')
            ->map(fn ($id) => (string) $id)
            ->merge(Employee::query()->whereIn('employee_id', $employeeIds)->whereNotNull('biometric_id')->pluck('biometric_id')->map(fn ($id) => (string) $id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }
}

<?php

namespace Modules\HRM\Interfaces\Attendance;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendancePair;

interface AttendanceDailyRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): AttendanceDaily;

    public function findByEmployeeAndDate(string $employeeId, string $dutyDate): ?AttendanceDaily;

    public function upsertForPair(AttendancePair $pair): AttendanceDaily;

    public function delete(AttendanceDaily $d): void;
    public function paginateForEmployee(string $code, string $from, string $to, int $perPage): LengthAwarePaginator;
    public function listForEmployeeRange(string $code, string $from, string $to): Collection;
    public function statusCountsForDate(string $date): Collection;
    public function statusesBetween(string $from, string $to): Collection;
    public function statusCountsBetween(string $from, string $to): Collection;
    public function topOffenders(string $from, string $to, array $statuses, int $limit): Collection;
}

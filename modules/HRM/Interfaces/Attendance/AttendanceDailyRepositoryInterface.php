<?php
namespace Modules\HRM\Interfaces\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Attendance\AttendanceDaily;
interface AttendanceDailyRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): AttendanceDaily;
    public function findByEmployeeAndDate(string $employeeId, string $dutyDate): ?AttendanceDaily;
    public function upsertForPair(\Modules\HRM\Models\Attendance\AttendancePair $pair): AttendanceDaily;
    public function delete(AttendanceDaily $d): void;
}

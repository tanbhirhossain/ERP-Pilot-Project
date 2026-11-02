<?php
namespace Modules\HRM\Interfaces\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Attendance\AttendancePair;
interface AttendancePairRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): AttendancePair;
    public function findOpenPair(string $employeeId, int $maxHours=72, ?string $punchTime = null, ?string $dutyDate = null): ?AttendancePair;
    public function hasPrimaryOnDate(string $employeeId, string $dutyDate): bool;
    public function create(array $data): AttendancePair;
    public function update(AttendancePair $p, array $data): AttendancePair;
    public function delete(AttendancePair $p): void;
}

<?php

namespace Modules\HRM\Interfaces\Employee;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Employee\Employee;

interface EmployeeRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int $id): Employee;

    public function findByEmployeeId(string $employeeId): ?Employee;

    public function create(array $data): Employee;

    public function update(Employee $e, array $data): Employee;

    public function delete(Employee $e): void;
    public function findByUserId(int $userId, array $with = []): ?Employee;
    public function teamMembers(?array $codes): Collection;
    public function directoryPaginate(?string $search, ?int $departmentId, ?string $status, int $perPage): LengthAwarePaginator;
    public function countActive(): int;
    public function countActiveByEmploymentType(string $type): int;
    public function countJoinedBetween(string $from, string $to): int;
    /** @return list<string> */
    public function knownCodes(array $codes): array;
    public function userIdForCode(string $code): ?int;
    /** @return list<int|string> */
    public function userIdsForCodes(array $codes): array;
    /** @return list<int|string> */
    public function userIdsForDepartments(array $departmentIds): array;
}

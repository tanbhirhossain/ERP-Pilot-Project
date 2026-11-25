<?php

namespace Modules\HRM\Interfaces\Roster;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Roster\RosterOvertime;

interface RosterOvertimeRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function attendanceApprovalQueue(DataTableQuery $dt, string $kind): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): RosterOvertime;
    public function find(int $id): ?RosterOvertime;
    public function create(array $data): RosterOvertime;
    public function update(RosterOvertime $overtime, array $data): RosterOvertime;
    public function findForEmployee(int $id, string $code, bool $withApprover = false): ?RosterOvertime;
    public function paginateForEmployee(string $code, ?string $type, ?string $status, ?string $from, ?string $to, int $perPage): LengthAwarePaginator;
    public function paginateForCodes(?array $codes, ?string $status, ?string $type, int $perPage): LengthAwarePaginator;
    public function duplicateExists(string $code, string $date, string $type): bool;
    public function pendingCountForEmployee(string $code): int;
    public function countPending(): int;
    public function pendingIds(): array;
    public function approvedHoursBetween(string $from, string $to): float;
    public function hoursByDepartmentBetween(string $from, string $to): Collection;
}

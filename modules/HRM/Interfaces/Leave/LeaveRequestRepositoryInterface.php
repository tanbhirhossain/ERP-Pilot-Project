<?php

namespace Modules\HRM\Interfaces\Leave;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Leave\LeaveRequest;

interface LeaveRequestRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): LeaveRequest;

    public function create(array $data): LeaveRequest;

    public function update(LeaveRequest $req, array $data): LeaveRequest;

    public function delete(LeaveRequest $req): void;
    public function find(int $id): ?LeaveRequest;
    public function findDetailed(int $id): ?LeaveRequest;
    public function findForEmployee(int $id, string $code): ?LeaveRequest;
    public function paginateForEmployee(string $code, ?string $status, ?int $year, ?int $typeId, int $perPage): LengthAwarePaginator;
    public function paginateForCodes(?array $codes, ?string $status, int $perPage): LengthAwarePaginator;
    public function pendingCountForEmployee(string $code): int;
    public function approvedDaysForYear(string $code, int $year): float;
    public function countPending(): int;
    public function onLeaveOn(string $date): Collection;
    public function countOnLeaveOn(string $date): int;
    public function approvedDaysBetween(string $from, string $to): float;
    public function approvedGroupedByType(string $from, string $to): Collection;
    /** @return list<int> */
    public function pendingIds(): array;
    public function pendingCreatedDates(): Collection;
}

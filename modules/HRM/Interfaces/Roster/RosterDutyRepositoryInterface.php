<?php

namespace Modules\HRM\Interfaces\Roster;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Roster\RosterDuty;

interface RosterDutyRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int $id): RosterDuty;

    public function create(array $data): RosterDuty;

    public function update(RosterDuty $d, array $data): RosterDuty;

    public function delete(RosterDuty $d): void;

    public function bulkAssign(array $employeeIds, int $shiftId, string $from, string $to): int;
    public function find(int $id): ?RosterDuty;
    public function paginateForCodes(?array $codes, ?string $from, ?string $to, int $perPage): LengthAwarePaginator;
}

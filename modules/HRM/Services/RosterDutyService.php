<?php

namespace Modules\HRM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Models\Roster\RosterDuty;

class RosterDutyService
{
    public function __construct(private readonly RosterDutyRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function options(?string $s = null, int $l = 25): Collection
    {
        return $this->repo->options($s, $l);
    }

    public function findOrFail(int $id): RosterDuty
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $d): RosterDuty
    {
        return $this->repo->create($d);
    }

    public function update(RosterDuty $d, array $data): RosterDuty
    {
        return $this->repo->update($d, $data);
    }

    public function delete(RosterDuty $d): void
    {
        $this->repo->delete($d);
    }

    public function bulkAssign(array $eIds, int $shiftId, string $from, string $to): int
    {
        return $this->repo->bulkAssign($eIds, $shiftId, $from, $to);
    }
}

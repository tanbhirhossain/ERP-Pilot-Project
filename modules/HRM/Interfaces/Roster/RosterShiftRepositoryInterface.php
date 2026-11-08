<?php

namespace Modules\HRM\Interfaces\Roster;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Roster\RosterShift;

interface RosterShiftRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int $id): RosterShift;

    public function create(array $data): RosterShift;

    public function update(RosterShift $s, array $data): RosterShift;

    public function delete(RosterShift $s): void;
}

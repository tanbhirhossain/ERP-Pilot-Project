<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\UomRepositoryInterface;
use Modules\INVENTORY\Models\Uom;

class UomService
{
    public function __construct(private readonly UomRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function options(?string $s = null, int $l = 100): Collection
    {
        return $this->repo->options($s, $l);
    }

    public function findOrFail(int $id): Uom
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $d): Uom
    {
        return $this->repo->create($d);
    }

    public function update(Uom $u, array $d): Uom
    {
        return $this->repo->update($u, $d);
    }

    public function delete(Uom $u): void
    {
        $this->repo->delete($u);
    }
}

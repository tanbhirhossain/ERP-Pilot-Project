<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\WarehouseRepositoryInterface;
use Modules\INVENTORY\Models\Warehouse;

class WarehouseService
{
    public function __construct(private readonly WarehouseRepositoryInterface $repo) {}

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

    public function findOrFail(int $id): Warehouse
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $d): Warehouse
    {
        return $this->repo->create($d);
    }

    public function update(Warehouse $w, array $d): Warehouse
    {
        return $this->repo->update($w, $d);
    }

    public function delete(Warehouse $w): void
    {
        $this->repo->delete($w);
    }
}

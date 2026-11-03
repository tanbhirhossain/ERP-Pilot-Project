<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Warehouse;

interface WarehouseRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search = null, int $limit = 100): Collection;
    public function findOrFail(int $id): Warehouse;
    public function create(array $data): Warehouse;
    public function update(Warehouse $warehouse, array $data): Warehouse;
    public function delete(Warehouse $warehouse): void;
}

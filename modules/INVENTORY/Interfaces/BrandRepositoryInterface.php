<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Brand;

interface BrandRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search = null, int $limit = 100): Collection;
    public function findOrFail(int $id): Brand;
    public function create(array $data): Brand;
    public function update(Brand $brand, array $data): Brand;
    public function delete(Brand $brand): void;
}

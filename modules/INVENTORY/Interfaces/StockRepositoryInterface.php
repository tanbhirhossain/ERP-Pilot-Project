<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Stock;

interface StockRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Stock;

    public function getForProduct(int $productId, ?int $warehouseId = null): Collection;

    public function getLowStock(): Collection;

    public function getOutOfStock(): Collection;
}

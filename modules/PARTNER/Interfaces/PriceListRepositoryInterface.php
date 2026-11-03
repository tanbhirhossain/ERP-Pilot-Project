<?php

namespace Modules\PARTNER\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Models\PriceList;

interface PriceListRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search = null, int $limit = 100): Collection;
    public function findOrFail(int $id): PriceList;
    public function create(array $data): PriceList;
    public function update(PriceList $priceList, array $data): PriceList;
    public function delete(PriceList $priceList): void;
}

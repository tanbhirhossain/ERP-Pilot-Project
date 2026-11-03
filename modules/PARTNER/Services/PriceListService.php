<?php

namespace Modules\PARTNER\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Interfaces\PriceListRepositoryInterface;
use Modules\PARTNER\Models\PriceList;

class PriceListService
{
    public function __construct(private readonly PriceListRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return $this->repo->options($search, $limit);
    }

    public function findOrFail(int $id): PriceList
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): PriceList
    {
        return $this->repo->create($data);
    }

    public function update(PriceList $pl, array $data): PriceList
    {
        return $this->repo->update($pl, $data);
    }

    public function delete(PriceList $pl): void
    {
        $this->repo->delete($pl);
    }
}

<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\ProductRepositoryInterface;
use Modules\INVENTORY\Models\Product;

class ProductService
{
    public function __construct(private readonly ProductRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return $this->repo->options($search, $limit);
    }

    public function findOrFail(int $id): Product
    {
        return $this->repo->findOrFail($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return $this->repo->findBySku($sku);
    }

    public function create(array $data): Product
    {
        return $this->repo->create($data);
    }

    public function update(Product $p, array $data): Product
    {
        return $this->repo->update($p, $data);
    }

    public function delete(Product $p): void
    {
        $this->repo->delete($p);
    }
}

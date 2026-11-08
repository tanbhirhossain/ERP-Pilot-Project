<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\CategoryRepositoryInterface;
use Modules\INVENTORY\Models\Category;

class CategoryService
{
    public function __construct(private readonly CategoryRepositoryInterface $repo) {}

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

    public function tree(): Collection
    {
        return $this->repo->tree();
    }

    public function findOrFail(int $id): Category
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Category
    {
        return $this->repo->create($data);
    }

    public function update(Category $c, array $data): Category
    {
        return $this->repo->update($c, $data);
    }

    public function delete(Category $c): void
    {
        $this->repo->delete($c);
    }
}

<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\BrandRepositoryInterface;
use Modules\INVENTORY\Models\Brand;

class BrandService
{
    public function __construct(private readonly BrandRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function options(?string $s = null, int $l = 100): Collection { return $this->repo->options($s, $l); }
    public function findOrFail(int $id): Brand { return $this->repo->findOrFail($id); }
    public function create(array $d): Brand { return $this->repo->create($d); }
    public function update(Brand $b, array $d): Brand { return $this->repo->update($b, $d); }
    public function delete(Brand $b): void { $this->repo->delete($b); }
}

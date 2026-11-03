<?php

namespace Modules\PARTNER\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Interfaces\PartnerGroupRepositoryInterface;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerGroupService
{
    public function __construct(private readonly PartnerGroupRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function options(?string $search = null, int $limit = 100, ?string $type = null): Collection
    {
        return $this->repo->options($search, $limit, $type);
    }

    public function tree(?string $type = null): Collection
    {
        return $this->repo->tree($type);
    }

    public function findOrFail(int $id): PartnerGroup
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): PartnerGroup
    {
        return $this->repo->create($data);
    }

    public function update(PartnerGroup $group, array $data): PartnerGroup
    {
        return $this->repo->update($group, $data);
    }

    public function delete(PartnerGroup $group): void
    {
        $this->repo->delete($group);
    }
}

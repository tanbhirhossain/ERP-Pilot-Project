<?php

namespace Modules\PARTNER\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Models\PartnerGroup;

interface PartnerGroupRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search = null, int $limit = 100, ?string $type = null): Collection;
    public function tree(?string $type = null): Collection;
    public function findOrFail(int $id): PartnerGroup;
    public function create(array $data): PartnerGroup;
    public function update(PartnerGroup $group, array $data): PartnerGroup;
    public function delete(PartnerGroup $group): void;
}

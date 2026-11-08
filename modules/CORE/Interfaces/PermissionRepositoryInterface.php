<?php

namespace Modules\CORE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Models\Permission;
use Modules\CORE\Support\DataTableQuery;

interface PermissionRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int|string $id): Permission;

    public function findWithRelations(int|string $id): Permission;

    /**
     * Distinct permission groups ("resource" part of "resource.action").
     *
     * @return Collection<int, string>
     */
    public function groups(): Collection;

    /**
     * How many roles / users currently depend on this permission.
     *
     * @return array{roles: int, users: int}
     */
    public function usageCounts(Permission $permission): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Permission;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Permission $permission, array $data): Permission;

    public function delete(Permission $permission): void;
}

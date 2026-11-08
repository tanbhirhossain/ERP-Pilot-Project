<?php

namespace Modules\CORE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Models\Role;
use Modules\CORE\Support\DataTableQuery;

interface RoleRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int|string $id): Role;

    public function findWithPermissions(int|string $id): Role;

    /**
     * All roles with their assigned users count — used by the list view.
     *
     * @return Collection<int, Role>
     */
    public function allWithCounts(): Collection;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $permissionIds
     */
    public function create(array $data, array $permissionIds = []): Role;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $permissionIds
     */
    public function update(Role $role, array $data, array $permissionIds): Role;

    public function delete(Role $role): void;
}

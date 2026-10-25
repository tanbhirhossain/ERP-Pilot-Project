<?php

namespace Modules\CORE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Interfaces\RoleRepositoryInterface;
use Modules\CORE\Models\Role;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class RoleRepository implements RoleRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyDataTable($query, $dt, ['name'], ['id' => 'id', 'name' => 'name', 'created_at' => 'created_at'], [
            'permission' => fn (Builder $query, string $value) => $query->whereHas(
                'permissions',
                fn (Builder $permissions) => $permissions->whereKey((int) $value)
            ),
        ]);

        return $query->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $query = $this->baseQuery();

        $this->applyDataTable($query, $dt, ['name'], ['id' => 'id', 'name' => 'name', 'created_at' => 'created_at'], [
            'permission' => fn (Builder $query, string $value) => $query->whereHas(
                'permissions',
                fn (Builder $permissions) => $permissions->whereKey((int) $value)
            ),
        ]);

        return $query;
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return Role::query()
            ->when($search, fn (Builder $query, string $term) => $query->where('name', 'like', '%'.addcslashes($term, '%_').'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => [
                'value' => $role->id,
                'label' => $role->name,
            ])
            ->values();
    }

    public function findOrFail(int|string $id): Role
    {
        return Role::query()->findOrFail($id);
    }

    public function findWithPermissions(int|string $id): Role
    {
        return Role::query()->with('permissions:id,name,guard_name')->findOrFail($id);
    }

    public function allWithCounts(): Collection
    {
        return Role::query()->withCount('users as users_count')->orderBy('name')->get();
    }

    public function create(array $data, array $permissionIds = []): Role
    {
        return DB::transaction(function () use ($data, $permissionIds): Role {
            $role = Role::query()->create($data);

            if ($permissionIds !== []) {
                $role->permissions()->sync($permissionIds);
            }

            return $role;
        });
    }

    public function update(Role $role, array $data, array $permissionIds): Role
    {
        return DB::transaction(function () use ($role, $data, $permissionIds): Role {
            $role->fill($data);
            $role->save();
            $role->permissions()->sync($permissionIds);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $role->permissions()->detach();
            $role->users()->detach();
            $role->delete();
        });
    }

    private function baseQuery(): Builder
    {
        return Role::query()->withCount(['users as users_count', 'permissions as permissions_count']);
    }
}

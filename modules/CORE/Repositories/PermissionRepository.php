<?php

namespace Modules\CORE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Interfaces\PermissionRepositoryInterface;
use Modules\CORE\Models\Permission;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class PermissionRepository implements PermissionRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->query($dt)->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->query($dt);
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return Permission::query()
            ->when($search, fn (Builder $query, string $term) => $query->where('name', 'like', '%'.addcslashes($term, '%_').'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Permission $permission): array => [
                'value' => $permission->id,
                'label' => $permission->name,
            ])
            ->values();
    }

    public function findOrFail(int|string $id): Permission
    {
        return Permission::query()->findOrFail($id);
    }

    public function findWithRelations(int|string $id): Permission
    {
        return Permission::query()->with('roles:id,name,guard_name')->findOrFail($id);
    }

    public function groups(): Collection
    {
        return Permission::query()
            ->pluck('name')
            ->map(fn (string $name): string => count(explode('.', $name)) > 1 ? ucfirst(explode('.', $name)[0]) : 'General')
            ->unique()
            ->sort()
            ->values();
    }

    public function usageCounts(Permission $permission): array
    {
        return [
            'roles' => $permission->roles()->count(),
            'users' => $permission->users()->count(),
        ];
    }

    public function create(array $data): Permission
    {
        return Permission::query()->create($data);
    }

    public function update(Permission $permission, array $data): Permission
    {
        $permission->fill($data);
        $permission->save();

        // Pivots reference permission ids, so a rename stays id-stable for
        // every role / user assignment — nothing else to rewrite.

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        DB::transaction(function () use ($permission): void {
            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();
        });
    }

    private function query(DataTableQuery $dt): Builder
    {
        $query = Permission::query()->withCount(['roles as roles_count', 'users as users_count']);

        $this->applyDataTable(
            $query,
            $dt,
            ['name'],
            ['id' => 'id', 'name' => 'name', 'guard_name' => 'guard_name', 'created_at' => 'created_at'],
            [
                'group' => function (Builder $query, string $value): void {
                    if ($value === 'General') {
                        $query->where('name', 'not like', '%.%');

                        return;
                    }

                    $query->where('name', 'like', strtolower($value).'.%');
                },
            ]
        );

        return $query;
    }
}

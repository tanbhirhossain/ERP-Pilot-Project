<?php

namespace Modules\CORE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Interfaces\RoleRepositoryInterface;
use Modules\CORE\Models\Role;
use Modules\CORE\Support\DataTableQuery;

class RoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly AuditTrailService $audit,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->roles->paginate($dt);
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return $this->roles->options($search, $limit);
    }

    public function allWithCounts(): Collection
    {
        return $this->roles->allWithCounts();
    }

    public function findOrFail(int|string $id): Role
    {
        return $this->roles->findOrFail($id);
    }

    public function findWithPermissions(int|string $id): Role
    {
        return $this->roles->findWithPermissions($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $permissionIds
     */
    public function create(array $data, array $permissionIds): Role
    {
        $role = $this->roles->create($data, $permissionIds);

        if ($permissionIds !== []) {
            $this->auditPermissionSync($role, [], $role->permissions()->pluck('name')->all());
        }

        return $role;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $permissionIds
     */
    public function update(Role $role, array $data, array $permissionIds): Role
    {
        $before = $role->permissions()->pluck('name')->all();

        $role = $this->roles->update($role, $data, $permissionIds);

        $after = $role->permissions()->pluck('name')->all();

        if ($this->sorted($before) !== $this->sorted($after)) {
            $this->auditPermissionSync($role, $before, $after);
        }

        return $role;
    }

    public function delete(Role $role): void
    {
        $protected = config('core.protected_roles', []);

        if (in_array($role->name, $protected, true)) {
            throw new \DomainException("The \"{$role->name}\" role is protected and cannot be deleted.");
        }

        $this->roles->delete($role);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function auditPermissionSync(Role $role, array $before, array $after): void
    {
        $this->audit->log(
            'roles',
            $role,
            'Permissions granted to role',
            'permissions_synced',
            ['before' => $this->sorted($before), 'after' => $this->sorted($after)],
        );
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}

<?php

namespace Modules\CORE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Interfaces\PermissionRepositoryInterface;
use Modules\CORE\Models\Permission;
use Modules\CORE\Support\DataTableQuery;

class PermissionService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
        private readonly AuditTrailService $audit,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->permissions->paginate($dt);
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return $this->permissions->options($search, $limit);
    }

    public function groups(): Collection
    {
        return $this->permissions->groups();
    }

    public function findOrFail(int|string $id): Permission
    {
        return $this->permissions->findOrFail($id);
    }

    public function findWithRelations(int|string $id): Permission
    {
        return $this->permissions->findWithRelations($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Permission
    {
        return $this->permissions->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Permission $permission, array $data): Permission
    {
        return $this->permissions->update($permission, $data);
    }

    public function delete(Permission $permission): void
    {
        if ($permission->name === '*') {
            throw new \DomainException('The wildcard ("*") permission is protected and cannot be deleted.');
        }

        $usage = $this->permissions->usageCounts($permission);

        if ($usage['roles'] > 0 || $usage['users'] > 0) {
            throw new \DomainException(
                "\"{$permission->name}\" is still in use by {$usage['roles']} role(s) and {$usage['users']} user(s) and cannot be deleted."
            );
        }

        $this->permissions->delete($permission);
    }
}

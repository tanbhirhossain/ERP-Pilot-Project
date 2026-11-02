<?php

namespace Modules\CORE\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\CORE\Models\User;
use Modules\CORE\Support\DataTableQuery;

/**
 * User management use-cases. Depends on abstractions, never on concrete
 * repositories (Dependency Inversion).
 */
class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditTrailService $audit,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->users->paginate($dt);
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return $this->users->options($search, $limit);
    }

    public function findOrFail(int|string $id): User
    {
        return $this->users->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $roleIds
     */
    public function create(array $data, array $roleIds): User
    {
        $user = $this->users->create($data, $roleIds);

        if ($roleIds !== []) {
            $this->auditAssignments($user, [], $user->roles()->pluck('name')->all());
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $roleIds
     */
    public function update(User $user, array $data, array $roleIds): User
    {
        $rolesBefore = $user->roles()->pluck('name')->all();

        $user = $this->users->update($user, $data, $roleIds);

        $rolesAfter = $user->roles()->pluck('name')->all();

        if (sort($rolesBefore) !== sort($rolesAfter)) {
            $this->auditAssignments($user, $rolesBefore, $rolesAfter);
        }

        return $user;
    }

    public function delete(User $user, ?Authenticatable $actor = null): void
    {
        if ($actor instanceof Authenticatable && method_exists($actor, 'is') && $actor->is($user)) {
            throw new \DomainException('You cannot delete your own account.');
        }

        $this->users->delete($user);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function auditAssignments(User $user, array $before, array $after): void
    {
        $this->audit->log(
            'users',
            $user,
            'Role assignments changed',
            'roles_synced',
            ['before' => $before, 'after' => $after],
        );
    }
}

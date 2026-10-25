<?php

namespace Modules\CORE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\CORE\Models\User;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class UserRepository implements UserRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyDataTable($query, $dt, $this->searchColumns(), $this->sortableColumns(), $this->filterHandlers());

        return $query->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $query = $this->baseQuery();

        $this->applyDataTable($query, $dt, $this->searchColumns(), $this->sortableColumns(), $this->filterHandlers());

        return $query;
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return User::query()
            ->when($search, fn (Builder $query, string $term) => $query->where(function (Builder $group) use ($term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $group->where('name', 'like', $needle)->orWhere('email', 'like', $needle);
            }))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
                'description' => $user->email,
            ])
            ->values();
    }

    public function findOrFail(int|string $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function create(array $data, array $roleIds = []): User
    {
        return DB::transaction(function () use ($data, $roleIds): User {
            $user = User::query()->create($data);

            if ($roleIds !== []) {
                $user->roles()->sync($roleIds);
            }

            return $user;
        });
    }

    public function update(User $user, array $data, array $roleIds): User
    {
        return DB::transaction(function () use ($user, $data, $roleIds): User {
            $user->fill($data);
            $user->save();
            $user->roles()->sync($roleIds);

            return $user;
        });
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->roles()->detach();
            $user->delete();
        });
    }

    private function baseQuery(): Builder
    {
        return User::query()->with(['roles:id,name,guard_name']);
    }

    /**
     * @return list<string>
     */
    private function searchColumns(): array
    {
        return ['name', 'email'];
    }

    /**
     * @return array<string, string>
     */
    private function sortableColumns(): array
    {
        return [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'verified' => 'email_verified_at',
            'created_at' => 'created_at',
        ];
    }

    /**
     * @return array<string, \Closure(Builder, string): void>
     */
    private function filterHandlers(): array
    {
        return [
            'role' => fn (Builder $query, string $value) => $query->whereHas(
                'roles',
                fn (Builder $roles) => $roles->whereKey((int) $value)
            ),
            'verified' => fn (Builder $query, string $value) => $value === '1'
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at'),
        ];
    }
}

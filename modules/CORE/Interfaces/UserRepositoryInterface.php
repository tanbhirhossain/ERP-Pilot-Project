<?php

namespace Modules\CORE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Models\User;
use Modules\CORE\Support\DataTableQuery;

interface UserRepositoryInterface
{
    /**
     * Fetch exactly one page of users for the DataTable.
     */
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    /**
     * The fully filtered (but un-paginated) query — used by exports so the
     * spreadsheet mirrors exactly what the screen is showing.
     */
    public function exportBuilder(DataTableQuery $dt): Builder;

    /**
     * Lightweight id/label pairs for searchable dropdowns.
     *
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int|string $id): User;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $roleIds
     */
    public function create(array $data, array $roleIds = []): User;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $roleIds
     */
    public function update(User $user, array $data, array $roleIds): User;

    public function delete(User $user): void;
}

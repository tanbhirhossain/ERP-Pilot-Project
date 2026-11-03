<?php

namespace Modules\ACCOUNTS\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ACCOUNTS\Models\Account;
use Modules\CORE\Support\DataTableQuery;

interface AccountRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): Account;
    public function findByCode(string $code): ?Account;
    public function options(?string $search, int $limit = 50): Collection;
    public function tree(): Collection;
    public function create(array $data): Account;
    public function update(Account $account, array $data): Account;
    public function delete(Account $account): void;
}

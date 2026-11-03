<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ACCOUNTS\Interfaces\AccountRepositoryInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\CORE\Support\DataTableQuery;

class AccountService
{
    public function __construct(private readonly AccountRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): Account { return $this->repo->findOrFail($id); }
    public function findByCode(string $code): ?Account { return $this->repo->findByCode($code); }
    public function options(?string $search, int $limit = 50): Collection { return $this->repo->options($search, $limit); }
    public function tree(): Collection { return $this->repo->tree(); }

    public function create(array $data): Account
    {
        // auto nature from type if not given
        if (empty($data['nature'])) {
            $map = ['asset' => 'debit', 'expense' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'income' => 'credit'];
            $data['nature'] = $map[$data['account_type']] ?? 'debit';
        }
        return $this->repo->create($data);
    }
    public function update(Account $account, array $data): Account { return $this->repo->update($account, $data); }
    public function delete(Account $account): void { $this->repo->delete($account); }

    public function ancestors(Account $account): Collection
    {
        if (! $account->path) return collect();
        $ids = array_filter(explode('/', $account->path));
        return Account::whereIn('id', $ids)->orderBy('level')->get();
    }
}

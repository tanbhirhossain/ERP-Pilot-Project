<?php

namespace Modules\ACCOUNTS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Interfaces\AccountRepositoryInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class AccountRepository implements AccountRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Account
    {
        return Account::with(['parent', 'children'])->findOrFail($id);
    }

    public function findByCode(string $code): ?Account
    {
        return Account::where('code', $code)->first();
    }

    public function options(?string $search, int $limit = 50): Collection
    {
        return Account::query()->posting()->active()
            ->when($search, fn (Builder $q) => $q->where(function (Builder $g) use ($search) {
                $n = '%'.addcslashes($search, '%_').'%';
                $g->where('code', 'like', $n)->orWhere('name', 'like', $n);
            }))
            ->orderBy('code')->limit($limit)
            ->get(['id', 'code', 'name', 'account_type', 'nature'])
            ->map(fn (Account $a) => [
                'value' => $a->id,
                'label' => "{$a->code} — {$a->name}",
                'description' => ucfirst($a->account_type).' · '.strtoupper($a->nature),
                'code' => $a->code,
                'type' => $a->account_type,
            ])->values();
    }

    public function tree(): Collection
    {
        return Account::with(['children' => fn ($q) => $q->orderBy('code')])
            ->whereNull('parent_id')->orderBy('code')->get();
    }

    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            $data['created_by'] = auth()->id();

            return Account::create($data);
        });
    }

    public function update(Account $account, array $data): Account
    {
        if ($account->is_system && isset($data['code']) && $data['code'] !== $account->code) {
            throw new \DomainException('System accounts code cannot be changed.');
        }
        $account->update($data);

        return $account->fresh(['parent', 'children']);
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new \DomainException('System accounts cannot be deleted.');
        }
        if ($account->children()->exists()) {
            throw new \DomainException('Cannot delete account with children. Move or delete children first.');
        }
        if ($account->journalLines()->exists() || $account->ledgerEntries()->exists()) {
            throw new \DomainException('Cannot delete account with postings. Deactivate instead.');
        }
        $account->delete();
    }

    private function baseQuery(): Builder
    {
        return Account::query()->with(['parent'])->withCount(['children', 'journalLines']);
    }

    private function searchColumns(): array
    {
        return ['acc_accounts.code', 'acc_accounts.name', 'acc_accounts.description'];
    }

    private function sortable(): array
    {
        return ['code' => 'acc_accounts.code', 'name' => 'acc_accounts.name', 'type' => 'acc_accounts.account_type', 'created_at' => 'acc_accounts.created_at'];
    }

    private function filters(): array
    {
        return [
            'account_type' => fn (Builder $q, string $v) => $q->where('acc_accounts.account_type', $v),
            'nature' => fn (Builder $q, string $v) => $q->where('acc_accounts.nature', $v),
            'is_group' => fn (Builder $q, string $v) => $q->where('acc_accounts.is_group', (bool) $v),
            'is_active' => fn (Builder $q, string $v) => $q->where('acc_accounts.is_active', (bool) $v),
            'parent_id' => fn (Builder $q, string $v) => $v === 'root' ? $q->whereNull('parent_id') : $q->where('parent_id', $v),
        ];
    }
}

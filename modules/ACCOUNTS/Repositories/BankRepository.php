<?php

namespace Modules\ACCOUNTS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ACCOUNTS\Interfaces\BankRepositoryInterface;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class BankRepository implements BankRepositoryInterface
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

    public function findOrFail(int $id): Bank
    {
        return Bank::with(['account', 'transactions'])->findOrFail($id);
    }

    public function options(?string $search, int $limit = 50): Collection
    {
        // LIKE wildcards escaped: a search for "%" or "_" is a literal, not a match-all. Bank
        // account numbers are digits, so an unescaped "_" silently matched any single character and
        // returned every bank in the company for a one-character search.
        $needle = '%'.addcslashes((string) $search, '%_\\').'%';

        return Bank::active()->when($search, fn (Builder $q) => $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('account_no', 'like', $needle)))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'account_no', 'bank_name'])->map(fn (Bank $b) => ['value' => $b->id, 'label' => "{$b->name} ({$b->account_no})", 'description' => $b->bank_name])->values();
    }

    public function create(array $data): Bank
    {
        $data['created_by'] = auth()->id();
        if (empty($data['code'])) {
            $data['code'] = 'BANK-'.str_pad((string) (Bank::max('id') + 1), 3, '0', STR_PAD_LEFT);
        }

        return Bank::create($data);
    }

    public function update(Bank $bank, array $data): Bank
    {
        $bank->update($data);

        return $bank->fresh(['account']);
    }

    public function delete(Bank $bank): void
    {
        if ($bank->transactions()->exists()) {
            throw new \DomainException('Cannot delete bank with transactions. Deactivate instead.');
        } $bank->delete();
    }

    private function baseQuery(): Builder
    {
        return Bank::query()->with(['account']);
    }

    private function searchColumns(): array
    {
        return ['acc_banks.name', 'acc_banks.bank_name', 'acc_banks.account_no'];
    }

    private function sortable(): array
    {
        return ['name' => 'acc_banks.name', 'created_at' => 'acc_banks.created_at'];
    }

    private function filters(): array
    {
        return ['is_active' => fn (Builder $q, string $v) => $q->where('acc_banks.is_active', (bool) $v)];
    }
}

<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ACCOUNTS\Interfaces\BankRepositoryInterface;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Support\DataTableQuery;

class BankService
{
    public function __construct(private readonly BankRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Bank
    {
        return $this->repo->findOrFail($id);
    }

    public function options(?string $search, int $limit = 50): Collection
    {
        return $this->repo->options($search, $limit);
    }

    public function create(array $data): Bank
    {
        return $this->repo->create($data);
    }

    public function update(Bank $bank, array $data): Bank
    {
        return $this->repo->update($bank, $data);
    }

    public function delete(Bank $bank): void
    {
        $this->repo->delete($bank);
    }
}

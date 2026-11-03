<?php

namespace Modules\ACCOUNTS\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Support\DataTableQuery;

interface BankRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): Bank;
    public function options(?string $search, int $limit = 50): Collection;
    public function create(array $data): Bank;
    public function update(Bank $bank, array $data): Bank;
    public function delete(Bank $bank): void;
}

<?php

namespace Modules\PURCHASE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\PurchaseReturn;

interface PurchaseReturnRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): PurchaseReturn;
    public function create(array $data): PurchaseReturn;
    public function update(PurchaseReturn $r, array $data): PurchaseReturn;
    public function delete(PurchaseReturn $r): void;
    public function nextNo(): string;
}

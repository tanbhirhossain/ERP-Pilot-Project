<?php

namespace Modules\PURCHASE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\PurchaseOrder;

interface PurchaseOrderRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): PurchaseOrder;

    public function create(array $data): PurchaseOrder;

    public function update(PurchaseOrder $order, array $data): PurchaseOrder;

    public function delete(PurchaseOrder $order): void;

    public function nextNo(): string;
}

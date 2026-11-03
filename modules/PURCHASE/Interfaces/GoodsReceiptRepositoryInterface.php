<?php

namespace Modules\PURCHASE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\GoodsReceipt;

interface GoodsReceiptRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): GoodsReceipt;
    public function create(array $data): GoodsReceipt;
    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt;
    public function delete(GoodsReceipt $receipt): void;
    public function nextNo(): string;
}
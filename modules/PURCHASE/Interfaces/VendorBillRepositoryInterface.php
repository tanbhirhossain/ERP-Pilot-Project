<?php

namespace Modules\PURCHASE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\VendorBill;

interface VendorBillRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): VendorBill;

    public function create(array $data): VendorBill;

    public function update(VendorBill $bill, array $data): VendorBill;

    public function delete(VendorBill $bill): void;

    public function nextNo(): string;
}

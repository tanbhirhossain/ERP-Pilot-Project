<?php

namespace Modules\SALES\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Models\Invoice;

interface InvoiceRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Invoice;

    public function create(array $data): Invoice;

    public function update(Invoice $inv, array $data): Invoice;

    public function delete(Invoice $inv): void;

    public function nextNo(): string;
}

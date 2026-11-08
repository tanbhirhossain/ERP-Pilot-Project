<?php

namespace Modules\SALES\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Models\Quotation;

interface QuotationRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Quotation;

    public function create(array $data): Quotation;

    public function update(Quotation $q, array $data): Quotation;

    public function delete(Quotation $q): void;

    public function nextNo(): string;
}

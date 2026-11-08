<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Transfer;

interface TransferRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Transfer;

    public function create(array $data): Transfer;

    public function update(Transfer $transfer, array $data): Transfer;

    public function delete(Transfer $transfer): void;
}

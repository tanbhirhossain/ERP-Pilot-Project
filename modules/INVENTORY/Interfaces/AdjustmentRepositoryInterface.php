<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Adjustment;

interface AdjustmentRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Adjustment;

    public function create(array $data): Adjustment;

    public function update(Adjustment $adjustment, array $data): Adjustment;

    public function delete(Adjustment $adjustment): void;
}

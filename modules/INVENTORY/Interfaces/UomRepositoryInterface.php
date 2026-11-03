<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Models\Uom;

interface UomRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search = null, int $limit = 100): Collection;
    public function findOrFail(int $id): Uom;
    public function create(array $data): Uom;
    public function update(Uom $uom, array $data): Uom;
    public function delete(Uom $uom): void;
}

<?php

namespace Modules\HRM\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Designation;

interface DesignationRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int $id): Designation;

    public function create(array $data): Designation;

    public function update(Designation $d, array $data): Designation;

    public function delete(Designation $d): void;
}

<?php

namespace Modules\ACCOUNTS\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\CORE\Support\DataTableQuery;

interface FiscalYearRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function findOrFail(int $id): FiscalYear;
    public function currentOpen(): ?FiscalYear;
    public function create(array $data): FiscalYear;
    public function update(FiscalYear $fy, array $data): FiscalYear;
}

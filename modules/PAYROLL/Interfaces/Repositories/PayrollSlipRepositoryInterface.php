<?php

namespace Modules\PAYROLL\Interfaces\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\PAYROLL\Models\PayrollSlip;

interface PayrollSlipRepositoryInterface
{
    public function paginateForEmployee(string $code, ?int $year, int $perPage): LengthAwarePaginator;

    public function findForEmployee(int $id, string $code): ?PayrollSlip;

    /** @return Collection<int, PayrollSlip> */
    public function latestForEmployee(string $code, int $limit): Collection;
}

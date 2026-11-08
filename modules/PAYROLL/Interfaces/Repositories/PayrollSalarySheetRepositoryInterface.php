<?php

namespace Modules\PAYROLL\Interfaces\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\PAYROLL\Models\PayrollSalarySheet;

interface PayrollSalarySheetRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findOrFail(int $id): PayrollSalarySheet;

    public function forPeriod(int $year, int $month): ?PayrollSalarySheet;

    public function createForPeriod(int $year, int $month, ?int $actorId, bool $includePunishments = false): PayrollSalarySheet;
}

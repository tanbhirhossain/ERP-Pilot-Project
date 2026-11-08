<?php

namespace Modules\PAYROLL\Interfaces\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\PAYROLL\Models\PayrollLoan;

interface PayrollLoanRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findOrFail(int $id): PayrollLoan;

    public function create(array $data): PayrollLoan;
}

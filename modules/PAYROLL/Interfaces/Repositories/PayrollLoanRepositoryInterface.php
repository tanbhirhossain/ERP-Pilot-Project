<?php

namespace Modules\PAYROLL\Interfaces\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\PAYROLL\Models\PayrollLoan;

interface PayrollLoanRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findOrFail(int $id): PayrollLoan;

    public function create(array $data): PayrollLoan;
    public function listForEmployee(string $code): Collection;
    public function findDetailed(int $id, string $code): ?PayrollLoan;
    public function pendingCountForEmployee(string $code): int;
}

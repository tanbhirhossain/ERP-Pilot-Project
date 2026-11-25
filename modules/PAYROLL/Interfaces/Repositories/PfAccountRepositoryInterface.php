<?php

namespace Modules\PAYROLL\Interfaces\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\PAYROLL\Models\PfAccount;

interface PfAccountRepositoryInterface
{
    public function findByEmployee(string $code, bool $withPolicy = false): ?PfAccount;

    /** @return array{employee: float, employer: float} */
    public function totals(): array;

    public function paginateLedger(PfAccount $account, ?string $from, ?string $to, ?string $entryType, int $perPage): LengthAwarePaginator;

    public function withdrawals(PfAccount $account): Collection;
}

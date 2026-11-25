<?php

namespace Modules\PAYROLL\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\PAYROLL\Interfaces\Repositories\PfAccountRepositoryInterface;
use Modules\PAYROLL\Models\PfAccount;

class PfAccountRepository implements PfAccountRepositoryInterface
{
    public function findByEmployee(string $code, bool $withPolicy = false): ?PfAccount
    {
        $query = PfAccount::where('employee_id', $code);

        if ($withPolicy) {
            $query->with('policy');
        }

        return $query->first();
    }

    public function totals(): array
    {
        return [
            'employee' => round((float) PfAccount::sum('employee_balance'), 2),
            'employer' => round((float) PfAccount::sum('employer_balance'), 2),
        ];
    }

    public function paginateLedger(PfAccount $account, ?string $from, ?string $to, ?string $entryType, int $perPage): LengthAwarePaginator
    {
        $query = $account->ledgers()->orderByDesc('entry_date')->orderByDesc('id');

        if ($from !== null && $from !== '') {
            $query->whereDate('entry_date', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate('entry_date', '<=', $to);
        }

        if ($entryType !== null && $entryType !== '') {
            $query->where('entry_type', $entryType);
        }

        return $query->paginate($perPage);
    }

    public function withdrawals(PfAccount $account): Collection
    {
        return $account->withdrawals()->orderByDesc('id')->get();
    }
}

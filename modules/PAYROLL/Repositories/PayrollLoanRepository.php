<?php

namespace Modules\PAYROLL\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Models\PayrollLoan;

final class PayrollLoanRepository implements PayrollLoanRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return PayrollLoan::query()
            ->with(['employee:id,employee_id,full_name'])->withCount([
                'installments',
                'installments as paid_installments_count' => fn ($query) => $query->where('status', 'paid'),
                'installments as remaining_installments_count' => fn ($query) => $query->whereIn('status', ['scheduled', 'attached']),
            ])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['employee_id']), fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $needle = '%'.addcslashes(trim($filters['search']), '%_').'%';
                $query->where(fn ($q) => $q->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle)->orWhereHas('employee', fn ($employee) => $employee->where('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle)));
            })
            ->latest('id')
            ->paginate(min(100, max(1, $perPage)))
            ->withQueryString();
    }

    public function findOrFail(int $id): PayrollLoan
    {
        return PayrollLoan::query()->with(['approvals.actor:id,name', 'installments'])->findOrFail($id);
    }

    public function create(array $data): PayrollLoan
    {
        return DB::transaction(fn (): PayrollLoan => PayrollLoan::query()->create($data));
    }
}

<?php

namespace Modules\PAYROLL\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSlipRepositoryInterface;
use Modules\PAYROLL\Models\PayrollSlip;

class PayrollSlipRepository implements PayrollSlipRepositoryInterface
{
    public function paginateForEmployee(string $code, ?int $year, int $perPage): LengthAwarePaginator
    {
        $query = PayrollSlip::where('employee_id', $code)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('pay_year')
            ->orderByDesc('pay_month')
            ->orderByDesc('id');

        if ($year !== null) {
            $query->where('pay_year', $year);
        }

        return $query->paginate($perPage);
    }

    public function findForEmployee(int $id, string $code): ?PayrollSlip
    {
        return PayrollSlip::where('employee_id', $code)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->find($id);
    }

    public function latestForEmployee(string $code, int $limit): Collection
    {
        return PayrollSlip::where('employee_id', $code)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'employee_id', 'salary_sheet_id', 'pay_year', 'pay_month', 'basic_salary', 'gross_salary', 'total_deductions', 'net_salary', 'payment_status', 'paid_at']);
    }
}

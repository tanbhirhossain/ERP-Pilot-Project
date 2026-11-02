<?php

namespace Modules\PAYROLL\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSalarySheetRepositoryInterface;
use Modules\PAYROLL\Models\PayrollSalarySheet;

final class PayrollSalarySheetRepository implements PayrollSalarySheetRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return PayrollSalarySheet::query()
            ->withCount('slips')
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['search']), function ($query) use ($filters): void { $search=trim((string)$filters['search']); if (preg_match('/^(\d{4})[-\/](\d{1,2})$/',$search,$match)) { $query->where('pay_year',(int)$match[1])->where('pay_month',(int)$match[2]); } else { $needle='%'.addcslashes($search,'%_').'%'; $query->where(fn ($q) => $q->where('status','like',$needle)->orWhere('pay_year','like',$needle)->orWhere('pay_month','like',$needle)); } })
            ->orderByDesc('pay_year')->orderByDesc('pay_month')
            ->paginate(min(100, max(1, $perPage)))
            ->withQueryString();
    }

    public function findOrFail(int $id): PayrollSalarySheet
    {
        return PayrollSalarySheet::query()->with('cutlist')->findOrFail($id);
    }

    public function forPeriod(int $year, int $month): ?PayrollSalarySheet
    {
        return PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->first();
    }

    public function createForPeriod(int $year, int $month, ?int $actorId, bool $includePunishments = false): PayrollSalarySheet
    {
        return PayrollSalarySheet::query()->firstOrCreate(
            ['pay_year' => $year, 'pay_month' => $month],
            ['status' => 'queued', 'created_by' => $actorId, 'include_punishments' => $includePunishments],
        );
    }
}

<?php

namespace Modules\ACCOUNTS\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ACCOUNTS\Interfaces\FiscalYearRepositoryInterface;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\CORE\Support\DataTableQuery;

class FiscalYearRepository implements FiscalYearRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return FiscalYear::withCount('periods')->orderByDesc('start_date')->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function findOrFail(int $id): FiscalYear
    {
        return FiscalYear::with('periods')->findOrFail($id);
    }

    public function currentOpen(): ?FiscalYear
    {
        return FiscalYear::where('status', 'open')->orderByDesc('start_date')->first();
    }

    public function create(array $data): FiscalYear
    {
        $fy = FiscalYear::create($data + ['created_by' => auth()->id()]);
        // auto-create 12 monthly periods
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $fy->periods()->create([
                'name' => $cursor->format('F Y'), 'month_no' => (int) $cursor->format('n'),
                'start_date' => $cursor->copy()->startOfMonth()->toDateString(),
                'end_date' => $cursor->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
            ]);
            $cursor->addMonthNoOverflow();
        }

        return $fy->load('periods');
    }

    public function update(FiscalYear $fy, array $data): FiscalYear
    {
        if ($fy->isClosed()) {
            throw new \DomainException('Closed fiscal year cannot be modified.');
        }
        $fy->update($data);

        return $fy->fresh('periods');
    }
}

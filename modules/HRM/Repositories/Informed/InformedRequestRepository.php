<?php

namespace Modules\HRM\Repositories\Informed;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Informed\InformedRequestRepositoryInterface;
use Modules\HRM\Models\Informed\InformedRequest;

class InformedRequestRepository implements InformedRequestRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): InformedRequest
    {
        return InformedRequest::with(['employee:id,employee_id,full_name', 'approver:id,name'])->findOrFail($id);
    }

    public function create(array $data): InformedRequest
    {
        return DB::transaction(fn () => InformedRequest::create($data));
    }

    public function update(InformedRequest $r, array $data): InformedRequest
    {
        return DB::transaction(function () use ($r, $data) {
            $r->fill($data);
            $r->save();

            return $r;
        });
    }

    public function delete(InformedRequest $r): void
    {
        DB::transaction(fn () => $r->delete());
    }

    private function baseQuery(): Builder
    {
        return InformedRequest::query()->with(['employee:id,employee_id,full_name', 'approver:id,name']);
    }

    private function searchColumns(): array
    {
        return ['employee_id', 'reason', 'informed_type'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'informed_date' => 'informed_date', 'status' => 'status', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('status', $v), 'employee_id' => fn (Builder $q, string $v) => $q->where('employee_id', $v), 'informed_type' => fn (Builder $q, string $v) => $q->where('informed_type', $v)];
    }
}

<?php

namespace Modules\HRM\Repositories\Leave;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Models\Leave\LeaveRequest;

class LeaveRequestRepository implements LeaveRequestRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $q->where(function (Builder $g) use ($needle) {
                $g->where('employee_id', 'like', $needle)->orWhere('reason', 'like', $needle)->orWhereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle));
            });
            $noSearch = new DataTableQuery(null, $dt->sort, $dt->direction, $dt->page, $dt->perPage, $dt->filters, $dt->defaultSort, $dt->defaultDirection);
            $this->applyDataTable($q, $noSearch, [], $this->sortable(), $this->filters());
        } else {
            $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        }

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): LeaveRequest
    {
        return LeaveRequest::with(['employee:id,employee_id,full_name', 'leaveType:id,name,code,color', 'approver:id,name'])->findOrFail($id);
    }

    public function create(array $data): LeaveRequest
    {
        return DB::transaction(fn () => LeaveRequest::create($data));
    }

    public function update(LeaveRequest $req, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($req, $data) {
            $req->fill($data);
            $req->save();

            return $req;
        });
    }

    public function delete(LeaveRequest $req): void
    {
        DB::transaction(fn () => $req->delete());
    }

    private function baseQuery(): Builder
    {
        return LeaveRequest::query()->with(['employee:id,employee_id,full_name', 'leaveType:id,name,code,color', 'approver:id,name']);
    }

    private function searchColumns(): array
    {
        return ['employee_id', 'reason'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'from_date' => 'from_date', 'status' => 'status', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('status', $v), 'employee_id' => fn (Builder $q, string $v) => $q->where('employee_id', $v), 'leave_type_id' => fn (Builder $q, string $v) => $q->where('leave_type_id', $v), 'department_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $v)), 'designation_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $e) => $e->where('designation_id', $v))];
    }
}

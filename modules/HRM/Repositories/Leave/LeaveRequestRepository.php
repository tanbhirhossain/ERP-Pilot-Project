<?php

namespace Modules\HRM\Repositories\Leave;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
    public function find(int $id): ?LeaveRequest
    {
        return LeaveRequest::find($id);
    }
    public function findDetailed(int $id): ?LeaveRequest
    {
        return LeaveRequest::with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])->find($id);
    }
    public function findForEmployee(int $id, string $code): ?LeaveRequest
    {
        return LeaveRequest::where('employee_id', $code)->find($id);
    }
    public function paginateForEmployee(string $code, ?string $status, ?int $year, ?int $typeId, int $perPage): LengthAwarePaginator
    {
        $query = LeaveRequest::where('employee_id', $code)
            ->with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($year !== null) {
            $query->whereYear('from_date', $year);
        }

        if ($typeId !== null) {
            $query->where('leave_type_id', $typeId);
        }

        return $query->paginate($perPage);
    }
    public function paginateForCodes(?array $codes, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = LeaveRequest::with(['employee:employee_id,full_name,first_name,last_name', 'leaveType:id,name,code,color'])
            ->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
    public function pendingCountForEmployee(string $code): int
    {
        return LeaveRequest::where('employee_id', $code)->where('status', 'Pending')->count();
    }
    public function approvedDaysForYear(string $code, int $year): float
    {
        return (float) LeaveRequest::where('employee_id', $code)
            ->where('status', 'Approved')
            ->whereYear('from_date', $year)
            ->sum('days');
    }
    public function countPending(): int
    {
        return LeaveRequest::where('status', 'Pending')->count();
    }
    public function onLeaveOn(string $date): Collection
    {
        return LeaveRequest::where('status', 'Approved')
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)
            ->with(['employee:employee_id,full_name,first_name,last_name', 'leaveType:id,name'])
            ->orderBy('to_date')->limit(10)->get();
    }
    public function countOnLeaveOn(string $date): int
    {
        return LeaveRequest::where('status', 'Approved')
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)
            ->count();
    }
    public function approvedDaysBetween(string $from, string $to): float
    {
        return round((float) LeaveRequest::where('status', 'Approved')
            ->whereBetween('from_date', [$from, $to])->sum('days'), 2);
    }
    public function approvedGroupedByType(string $from, string $to): Collection
    {
        return LeaveRequest::where('status', 'Approved')
            ->whereBetween('from_date', [$from, $to])
            ->selectRaw('leave_type_id, COUNT(*) AS n, SUM(days) AS d')->groupBy('leave_type_id')->get();
    }
    public function pendingIds(): array
    {
        return LeaveRequest::where('status', 'Pending')->pluck('id')->all();
    }
    public function pendingCreatedDates(): Collection
    {
        return LeaveRequest::where('status', 'Pending')->get(['created_at']);
    }
}

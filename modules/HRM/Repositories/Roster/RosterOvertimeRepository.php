<?php

namespace Modules\HRM\Repositories\Roster;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Models\Roster\RosterOvertime;

class RosterOvertimeRepository implements RosterOvertimeRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $q->where(function (Builder $g) use ($needle) {
                $g->where('employee_id', 'like', $needle)->orWhere('reason', 'like', $needle)->orWhere('overtime_type', 'like', $needle)->orWhereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle));
            });
            $noSearch = new DataTableQuery(null, $dt->sort, $dt->direction, $dt->page, $dt->perPage, $dt->filters, $dt->defaultSort, $dt->defaultDirection);
            $this->applyDataTable($q, $noSearch, [], $this->sortable(), $this->filters());
        } else {
            $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        }

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function attendanceApprovalQueue(DataTableQuery $dt, string $kind): LengthAwarePaginator
    {
        $q = $this->attendanceQueueQuery($kind);
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['roster_overtimes.*'], 'page', $dt->page)->withQueryString();
    }

    /**
     * Every filter the approval queue offers, expressed once.
     *
     * The list, the footer totals and the printable sheet all read attendance through this method,
     * so a filter added here can never apply to one of them and silently miss the others.
     */
    public function attendanceQueueQuery(string $kind): Builder
    {
        $q = $this->baseQuery()->where('roster_overtimes.status', 'Pending');

        if ($kind === 'Overtime') {
            $q->where('roster_overtimes.overtime_type', 'Overtime')
                ->whereExists(function ($daily) {
                    $daily->selectRaw('1')->from('attendance_dailies')
                        ->whereColumn('attendance_dailies.employee_id', 'roster_overtimes.employee_id')
                        // duty_date is stored in both shapes ('2026-10-17' and '2026-10-17 00:00:00'),
                        // so the link must be made on the date, not on the raw column value.
                        ->whereRaw('date(attendance_dailies.duty_date) = date(roster_overtimes.overtime_date)')
                        ->where('attendance_dailies.total_overtime_hours', '>', 0.5);
                });
        } else {
            $q->whereIn('roster_overtimes.overtime_type', ['Extra Duty', 'Holiday Duty'])
                ->whereExists(function ($daily) {
                    $daily->selectRaw('1')->from('attendance_dailies')
                        ->whereColumn('attendance_dailies.employee_id', 'roster_overtimes.employee_id')
                        ->whereRaw('date(attendance_dailies.duty_date) = date(roster_overtimes.overtime_date)')
                        ->where('attendance_dailies.status', 'Extra Duty');
                });
        }

        return $q;
    }

    /**
     * Apply the operator's search and filters to a queue query without paginating it.
     *
     * Public so the footer totals and the printable sheet read exactly the view the table shows;
     * they would otherwise have to re-implement the filter map and could drift from it.
     */
    public function applyQueueFilters(Builder $q, DataTableQuery $dt): Builder
    {
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): RosterOvertime
    {
        return RosterOvertime::with(['employee:id,employee_id,full_name', 'shift:id,shift_name', 'approver:id,name'])->findOrFail($id);
    }

    private function baseQuery(): Builder
    {
        return RosterOvertime::query()->with(['employee:id,employee_id,full_name', 'shift:id,shift_name', 'approver:id,name']);
    }

    private function searchColumns(): array
    {
        return ['employee_id', 'reason', 'overtime_type'];
    }

    private function sortable(): array
    {
        return ['id' => 'roster_overtimes.id', 'overtime_date' => 'roster_overtimes.overtime_date', 'hours' => 'roster_overtimes.hours', 'overtime_type' => 'roster_overtimes.overtime_type', 'status' => 'roster_overtimes.status', 'created_at' => 'roster_overtimes.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('status', $v), 'employee_id' => fn (Builder $q, string $v) => $q->where('employee_id', $v),
            // Inclusive date range. Compared on date() because overtime_date is stored with a
            // 00:00:00 time component, so a plain `<= '2026-10-17'` would drop the 17th itself.
            'date_from' => fn (Builder $q, string $v) => $q->whereRaw('date(roster_overtimes.overtime_date) >= ?', [$v]),
            'date_to' => fn (Builder $q, string $v) => $q->whereRaw('date(roster_overtimes.overtime_date) <= ?', [$v]), 'overtime_type' => function (Builder $q, string $v) {
            if ($v === 'Extra Duty') {
                $q->whereIn('overtime_type', ['Extra Duty', 'Holiday Duty']);
            } else {
                $q->where('overtime_type', $v);
            }
        }, 'department_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $v)), 'designation_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $e) => $e->where('designation_id', $v))];
    }
    public function find(int $id): ?RosterOvertime
    {
        return RosterOvertime::find($id);
    }
    public function create(array $data): RosterOvertime
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => RosterOvertime::create($data));
    }
    public function update(RosterOvertime $overtime, array $data): RosterOvertime
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($overtime, $data) {
            $overtime->fill($data);
            $overtime->save();

            return $overtime;
        });
    }
    public function findForEmployee(int $id, string $code, bool $withApprover = false): ?RosterOvertime
    {
        $query = RosterOvertime::where('employee_id', $code);

        if ($withApprover) {
            $query->with('approver:id,name');
        }

        return $query->find($id);
    }
    public function paginateForEmployee(string $code, ?string $type, ?string $status, ?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        $query = RosterOvertime::where('employee_id', $code)
            ->with('approver:id,name')
            ->orderByDesc('overtime_date')
            ->orderByDesc('id');

        if ($type !== null && $type !== '') {
            $query->where('overtime_type', $type);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($from !== null && $from !== '') {
            $query->whereDate('overtime_date', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate('overtime_date', '<=', $to);
        }

        return $query->paginate($perPage);
    }
    public function paginateForCodes(?array $codes, ?string $status, ?string $type, int $perPage): LengthAwarePaginator
    {
        $query = RosterOvertime::with(['employee:employee_id,full_name,first_name,last_name', 'approver:id,name'])
            ->orderByDesc('overtime_date')->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($type !== null && $type !== '') {
            $query->where('overtime_type', $type);
        }

        return $query->paginate($perPage);
    }
    public function duplicateExists(string $code, string $date, string $type): bool
    {
        return RosterOvertime::where('employee_id', $code)
            ->whereDate('overtime_date', $date)
            ->where('overtime_type', $type)
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();
    }
    public function pendingCountForEmployee(string $code): int
    {
        return RosterOvertime::where('employee_id', $code)->where('status', 'Pending')->count();
    }
    public function countPending(): int
    {
        return RosterOvertime::where('status', 'Pending')->count();
    }
    public function pendingIds(): array
    {
        return RosterOvertime::where('status', 'Pending')->pluck('id')->all();
    }
    public function approvedHoursBetween(string $from, string $to): float
    {
        return round((float) RosterOvertime::where('status', 'Approved')
            ->whereBetween('overtime_date', [$from, $to])->sum('hours'), 2);
    }
    public function hoursByDepartmentBetween(string $from, string $to): Collection
    {
        return RosterOvertime::where('roster_overtimes.status', 'Approved')
            ->whereBetween('overtime_date', [$from, $to])
            ->join('employees', 'employees.employee_id', '=', 'roster_overtimes.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('COALESCE(departments.name, ?) AS dept, SUM(roster_overtimes.hours) AS h, COUNT(*) AS n', ['Unassigned'])
            ->groupBy('dept')->orderByDesc('h')->get();
    }
}

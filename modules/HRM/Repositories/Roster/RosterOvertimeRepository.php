<?php

namespace Modules\HRM\Repositories\Roster;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
}

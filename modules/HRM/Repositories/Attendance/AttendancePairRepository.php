<?php

namespace Modules\HRM\Repositories\Attendance;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendancePair;

class AttendancePairRepository implements AttendancePairRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        // Name-aware search via employee relation (DataTable's searchColumns only handles direct columns)
        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $q->where(function (Builder $g) use ($needle) {
                $g->where('employee_id', 'like', $needle)
                    ->orWhereHas('employee', fn (Builder $qq) => $qq->where('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle));
            });
            // Create a stripped query without searchColumns to avoid double-filter; then apply filters/sort only
            $dtNoSearch = new DataTableQuery(null, $dt->sort, $dt->direction, $dt->page, $dt->perPage, $dt->filters, $dt->defaultSort, $dt->defaultDirection);
            $this->applyDataTable($q, $dtNoSearch, [], $this->sortable(), $this->filters());
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

    public function findOrFail(int $id): AttendancePair
    {
        return AttendancePair::with(['employee', 'shift'])->findOrFail($id);
    }

    public function findOpenPair(string $employeeId, int $maxHours = 72, ?string $punchTime = null, ?string $dutyDate = null): ?AttendancePair
    {
        if ($dutyDate) {
            $q = AttendancePair::where('employee_id', $employeeId)->whereNull('punch_out')->whereDate('duty_date', $dutyDate);
            if ($punchTime && $maxHours > 24) {
                $ref = Carbon::parse($punchTime);
                $q->where('punch_in', '>=', $ref->copy()->subHours($maxHours))->where('punch_in', '<=', $ref);
            }

            return $q->orderBy('punch_in', 'asc')->first();
        }
        $q = AttendancePair::where('employee_id', $employeeId)->whereNull('punch_out');
        if ($punchTime && $maxHours > 24) {
            $ref = Carbon::parse($punchTime);
            $q->where('punch_in', '>=', $ref->copy()->subHours($maxHours))->where('punch_in', '<=', $ref);
        } elseif ($punchTime && ! $dutyDate) {
            $ref = Carbon::parse($punchTime);
            $q->where('punch_in', '>=', $ref->copy()->subHours($maxHours))->where('punch_in', '<=', $ref);
        } elseif (! $punchTime && ! $dutyDate) {
            $q->where('punch_in', '>=', now()->subHours($maxHours));
        }

        return $q->orderBy('punch_in', 'asc')->first();
    }

    public function hasPrimaryOnDate(string $employeeId, string $dutyDate): bool
    {
        return AttendancePair::where('employee_id', $employeeId)->whereDate('duty_date', $dutyDate)->where('duty_type', 'Primary')->exists();
    }

    public function create(array $data): AttendancePair
    {
        if (! empty($data['punch_in']) && ! empty($data['punch_out'])) {
            $in = new \DateTime($data['punch_in']);
            $out = new \DateTime($data['punch_out']);
            $diff = $in->diff($out);
            $hours = $diff->h + $diff->days * 24 + $diff->i / 60 + $diff->s / 3600;
            $data['worked_hours'] = round($hours, 2);
        }

        return AttendancePair::create($data);
    }

    public function update(AttendancePair $p, array $data): AttendancePair
    {
        if (isset($data['punch_in']) || isset($data['punch_out'])) {
            $in = new \DateTime($data['punch_in'] ?? $p->punch_in);
            $out = new \DateTime($data['punch_out'] ?? $p->punch_out);
            if ($out && $in) {
                $diff = $in->diff($out);
                $hours = $diff->h + $diff->days * 24 + $diff->i / 60 + $diff->s / 3600;
                $data['worked_hours'] = round($hours, 2);
            }
        }
        $p->fill($data);
        $p->save();

        return $p;
    }

    public function delete(AttendancePair $p): void
    {
        $p->delete();
    }

    private function baseQuery(): Builder
    {
        return AttendancePair::query()->with(['employee:id,employee_id,full_name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,name', 'shift:id,shift_name,start_time,end_time']);
    }

    private function searchColumns(): array
    {
        return ['employee_id'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'duty_date' => 'duty_date', 'punch_in' => 'punch_in', 'worked_hours' => 'worked_hours', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return [
            'employee_id' => fn (Builder $q, string $v) => $q->where('employee_id', $v),
            'duty_type' => fn (Builder $q, string $v) => $q->where('duty_type', $v),
            'shift_id' => fn (Builder $q, string $v) => $q->where('shift_id', $v),
            'duty_date' => fn (Builder $q, string $v) => $q->where('duty_date', $v),
            'from' => fn (Builder $q, string $v) => $q->whereDate('duty_date', '>=', $v),
            'to' => fn (Builder $q, string $v) => $q->whereDate('duty_date', '<=', $v),
            'department_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $qq) => $qq->where('department_id', $v)),
            'designation_id' => fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $qq) => $qq->where('designation_id', $v)),
        ];
    }
}

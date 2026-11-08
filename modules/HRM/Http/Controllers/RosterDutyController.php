<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Requests\BulkRosterAssignRequest;
use Modules\HRM\Requests\StoreRosterDutyRequest;
use Modules\HRM\Requests\UpdateRosterDutyRequest;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\RosterDutyService;
use Modules\HRM\Services\RosterShiftService;

class RosterDutyController extends Controller
{
    public function __construct(
        private readonly RosterDutyService $svc,
        private readonly RosterShiftService $shifts,
        private readonly EmployeeService $employees
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            ['id', 'roster_date', 'created_at'],
            ['employee_id', 'shift_id', 'status', 'roster_date']
        );

        return Inertia::render('HRM::RosterDuties/Index', [
            'duties' => PaginatedResource::from(
                $this->svc->paginate($dt)->through(fn (RosterDuty $d) => [
                    'id' => $d->id,
                    'employee_id' => $d->employee_id,
                    'employee' => $d->employee?->full_name ?? $d->employee?->first_name ?? $d->employee_id,
                    'shift' => $d->shift ? [
                        'id' => $d->shift->id,
                        'shift_name' => $d->shift->shift_name,
                        'start_time' => $d->shift->start_time,
                        'end_time' => $d->shift->end_time,
                    ] : null,
                    'roster_date' => $d->roster_date?->toDateString(),
                    'scheduled_start_time' => $d->scheduled_start_time,
                    'scheduled_end_time' => $d->scheduled_end_time,
                    'status' => $d->status,
                    'remarks' => $d->remarks,
                    'created_at' => $d->created_at?->toIso8601String(),
                ])
            ),
            'shiftOptions' => $this->shifts->options(null, 200),
            'employeeOptions' => $this->employees->options(null, 200),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::RosterDuties/Create', [
            'shiftOptions' => $this->shifts->options(null, 200),
            'employeeOptions' => $this->employees->options(null, 200),
        ]);
    }

    public function store(StoreRosterDutyRequest $request): RedirectResponse
    {
        $this->svc->create($request->validated());

        return redirect()->route('hrm.duties.index')->with('success', 'Duty assigned successfully.');
    }

    public function edit(RosterDuty $duty): Response
    {
        return Inertia::render('HRM::RosterDuties/Edit', [
            'duty' => [
                'id' => $duty->id,
                'employee_id' => $duty->employee_id,
                'shift_id' => $duty->shift_id,
                'roster_date' => $duty->roster_date?->toDateString(),
                'status' => $duty->status,
                'remarks' => $duty->remarks,
            ],
            'shiftOptions' => $this->shifts->options(null, 200),
            'employeeOptions' => $this->employees->options(null, 200),
        ]);
    }

    public function update(UpdateRosterDutyRequest $request, RosterDuty $duty): RedirectResponse
    {
        $this->svc->update($duty, $request->validated());

        return redirect()->route('hrm.duties.index')->with('success', 'Duty updated successfully.');
    }

    public function destroy(RosterDuty $duty): RedirectResponse
    {
        $this->svc->delete($duty);

        return redirect()->route('hrm.duties.index')->with('success', 'Duty removed.');
    }

    // Excel-like Roster Entry
    public function excel(Request $r): Response
    {
        $from = $r->query('from', now()->startOfMonth()->toDateString());
        $to = $r->query('to', now()->endOfMonth()->toDateString());
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
            $to = Carbon::parse($from)->addDays(30)->toDateString();
        }

        return Inertia::render('HRM::RosterDuties/Excel', [
            'initialFrom' => $from,
            'initialTo' => $to,
            'shiftOptions' => $this->shifts->options(null, 200)->map(fn ($s) => ['value' => $s['value'], 'label' => $s['label']])->values()->all(),
            'shifts' => RosterShift::orderBy('shift_name')->get(['id', 'shift_name', 'start_time', 'end_time', 'crosses_midnight'])->map(fn ($s) => ['id' => $s->id, 'shift_name' => $s->shift_name, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'crosses_midnight' => $s->crosses_midnight])->values()->all(),
        ]);
    }

    public function bulkWeek(Request $r): Response
    {
        return Inertia::render('HRM::RosterDuties/BulkWeek', [
            'shiftOptions' => $this->shifts->options(null, 200)->map(fn ($s) => ['value' => $s['value'], 'label' => $s['label']])->values()->all(),
            'shifts' => RosterShift::orderBy('shift_name')->get(['id', 'shift_name', 'start_time', 'end_time', 'crosses_midnight'])->map(fn ($s) => ['id' => $s->id, 'shift_name' => $s->shift_name, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'crosses_midnight' => $s->crosses_midnight])->values()->all(),
        ]);
    }

    public function excelData(Request $r)
    {
        $r->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $from = $r->input('from');
        $to = $r->input('to');
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
            return response()->json(['message' => 'Range cannot exceed 31 days'], 422);
        }
        $perPage = (int) ($r->input('per_page', 50));
        $q = Employee::query()->with(['department:id,name', 'designation:id,name'])
            ->where('is_active', true)->where('status', 'Active');
        if ($r->filled('department_id')) {
            $q->where('department_id', $r->input('department_id'));
        }
        if ($r->filled('search')) {
            $s = '%'.addcslashes($r->input('search'), '%_').'%';
            $q->where(fn ($g) => $g->where('employee_id', 'like', $s)->orWhere('full_name', 'like', $s)->orWhere('first_name', 'like', $s));
        }
        $paginator = $q->orderBy('employee_id')->paginate($perPage, ['id', 'employee_id', 'full_name', 'first_name', 'last_name', 'department_id', 'designation_id'], 'page', $r->input('page', 1));
        $employeeIds = $paginator->pluck('employee_id')->all();
        $duties = RosterDuty::whereIn('employee_id', $employeeIds)->whereBetween('roster_date', [$from, $to])->get(['employee_id', 'roster_date', 'shift_id', 'status'])->keyBy(fn ($d) => $d->employee_id.'|'.$d->roster_date->toDateString());
        // Build dates array
        $dates = [];
        $cur = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cur->lte($end)) {
            $dates[] = $cur->toDateString();
            $cur->addDay();
        }
        // departments for filter
        $departments = Department::orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->values()->all();

        return response()->json([
            'employees' => $paginator->through(fn ($e) => ['employee_id' => $e->employee_id, 'full_name' => $e->full_name ?? trim($e->first_name.' '.$e->last_name), 'department' => $e->department?->name, 'designation' => $e->designation?->name])->items(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            'duties' => $duties->map(fn ($d) => ['employee_id' => $d->employee_id, 'roster_date' => $d->roster_date->toDateString(), 'shift_id' => $d->shift_id, 'status' => $d->status])->values()->all(),
            'dates' => $dates,
            'departments' => $departments,
        ]);
    }

    public function excelSave(Request $r)
    {
        $data = $r->validate([
            'duties' => ['required', 'array', 'min:1', 'max:5000'],
            'duties.*.employee_id' => ['required', 'exists:employees,employee_id'],
            'duties.*.roster_date' => ['required', 'date'],
            'duties.*.shift_id' => ['nullable', 'exists:roster_shifts,id'],
        ]);
        $count = 0;
        $toDelete = [];
        $toUpsert = [];
        foreach ($data['duties'] as $row) {
            if ($row['shift_id'] === null || $row['shift_id'] === '' || $row['shift_id'] === 0) {
                $toDelete[] = ['employee_id' => $row['employee_id'], 'roster_date' => $row['roster_date']];
            } else {
                $shift = RosterShift::find($row['shift_id']);
                $toUpsert[] = [
                    'employee_id' => $row['employee_id'],
                    'roster_date' => $row['roster_date'],
                    'shift_id' => $row['shift_id'],
                    'scheduled_start_time' => $shift?->start_time,
                    'scheduled_end_time' => $shift?->end_time,
                    'status' => 'Scheduled',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        DB::transaction(function () use ($toDelete, $toUpsert) {
            if (! empty($toDelete)) {
                // fetch for audit before bulk delete
                $toDeleteModels = RosterDuty::where(function ($q) use ($toDelete) {
                    foreach ($toDelete as $d) {
                        $q->orWhere(function ($qq) use ($d) {
                            $qq->where('employee_id', $d['employee_id'])->where('roster_date', $d['roster_date']);
                        });
                    }
                })->get();
                foreach ($toDeleteModels as $m) {
                    activity()->performedOn($m)->useLog('hrm_roster_duty')->log('Excel bulk delete — cleared by '.auth()->id());
                }
                foreach ($toDelete as $d) {
                    RosterDuty::where('employee_id', $d['employee_id'])->where('roster_date', $d['roster_date'])->delete();
                }
            }
            if (! empty($toUpsert)) {
                RosterDuty::upsert($toUpsert, ['employee_id', 'roster_date'], ['shift_id', 'scheduled_start_time', 'scheduled_end_time', 'status', 'updated_at']);
                activity()->useLog('hrm_roster_duty')->log('Excel bulk upsert '.count($toUpsert).' duties by '.auth()->id());
            }
        });
        $count = count($toUpsert) + count($toDelete);

        return back()->with('success', 'Excel saved: '.count($toUpsert).' duties upserted, '.count($toDelete)." cleared ($count changes)");
    }

    /**
     * Bulk assign: POST /hrm/duties/bulk
     * Must be defined BEFORE {duty} wildcard to avoid route shadowing.
     */
    public function bulkStore(BulkRosterAssignRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $count = $this->svc->bulkAssign(
            $validated['employee_ids'],
            $validated['shift_id'],
            $validated['from_date'],
            $validated['to_date']
        );

        return redirect()->route('hrm.duties.index')
            ->with('success', "Bulk assigned {$count} duties successfully.");
    }
}

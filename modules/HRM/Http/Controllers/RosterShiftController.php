<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Requests\StoreRosterShiftRequest;
use Modules\HRM\Requests\UpdateRosterShiftRequest;
use Modules\HRM\Services\RosterShiftService;

class RosterShiftController extends Controller
{
    public function __construct(private readonly RosterShiftService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'shift_name', 'start_time', 'created_at'], ['crosses_midnight']);

        return Inertia::render('HRM::RosterShifts/Index', ['shifts' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (RosterShift $s) => ['id' => $s->id, 'shift_name' => $s->shift_name, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'punch_in_window_start' => $s->punch_in_window_start, 'punch_in_window_end' => $s->punch_in_window_end, 'crosses_midnight' => $s->crosses_midnight, 'max_duration_hours' => $s->max_duration_hours, 'created_at' => $s->created_at?->toIso8601String()]))]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::RosterShifts/Create');
    }

    public function store(StoreRosterShiftRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.shifts.index')->with('success', 'Shift created.');
    }

    public function edit(RosterShift $shift): Response
    {
        return Inertia::render('HRM::RosterShifts/Edit', ['shift' => ['id' => $shift->id, 'shift_name' => $shift->shift_name, 'start_time' => $shift->start_time, 'end_time' => $shift->end_time, 'punch_in_window_start' => $shift->punch_in_window_start, 'punch_in_window_end' => $shift->punch_in_window_end, 'crosses_midnight' => $shift->crosses_midnight, 'max_duration_hours' => $shift->max_duration_hours]]);
    }

    public function update(UpdateRosterShiftRequest $req, RosterShift $shift): RedirectResponse
    {
        $this->svc->update($shift, $req->validated());

        return redirect()->route('hrm.shifts.index')->with('success', 'Shift updated.');
    }

    public function destroy(RosterShift $shift): RedirectResponse
    {
        $this->svc->delete($shift);

        return redirect()->route('hrm.shifts.index')->with('success', 'Shift deleted.');
    }
}

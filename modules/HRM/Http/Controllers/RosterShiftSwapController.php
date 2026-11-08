<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Roster\RosterShiftSwap;
use Modules\HRM\Requests\StoreRosterShiftSwapRequest;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\RosterShiftService;
use Modules\HRM\Services\RosterShiftSwapService;

class RosterShiftSwapController extends Controller
{
    public function __construct(private readonly RosterShiftSwapService $svc, private readonly EmployeeService $employees, private readonly RosterShiftService $shifts) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'swap_date', 'created_at'], ['status', 'requester_employee_id']);
        $page = $this->svc->paginate($dt);
        $mapped = $page->through(fn (RosterShiftSwap $s) => [
            'id' => $s->id,
            'swap_date' => $s->swap_date?->toDateString(),
            'requester' => $s->requester?->full_name ?? $s->requester_employee_id,
            'requester_employee_id' => $s->requester_employee_id,
            'target_employee_id' => $s->target_employee_id,
            'requested_shift' => $s->requestedShift?->shift_name,
            'target_shift' => $s->targetShift?->shift_name,
            'reason' => $s->reason,
            'status' => $s->status,
            'created_at' => $s->created_at?->toIso8601String(),
        ]);

        return Inertia::render('HRM::RosterSwaps/Index', [
            'swaps' => PaginatedResource::from($mapped),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::RosterSwaps/Create', [
            'employeeOptions' => $this->employees->options(null, 200),
            'shiftOptions' => $this->shifts->options(null, 200),
        ]);
    }

    public function store(StoreRosterShiftSwapRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.swaps.index')->with('success', 'Swap request created — Pending approval');
    }

    public function show(RosterShiftSwap $swap): Response
    {
        $swap = $this->svc->findOrFail($swap->id);

        return Inertia::render('HRM::RosterSwaps/Show', [
            'swap' => [
                'id' => $swap->id,
                'swap_date' => $swap->swap_date?->toDateString(),
                'requester_employee_id' => $swap->requester_employee_id,
                'target_employee_id' => $swap->target_employee_id,
                'requested_shift_id' => $swap->requested_shift_id,
                'target_shift_id' => $swap->target_shift_id,
                'reason' => $swap->reason,
                'status' => $swap->status,
                'requester' => $swap->requester?->full_name ?? $swap->requester_employee_id,
                'requestedShift' => $swap->requestedShift?->shift_name,
                'created_at' => $swap->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function approve(RosterShiftSwap $swap): RedirectResponse
    {
        try {
            $this->svc->approve($swap);

            return back()->with('success', 'Swap approved and duties exchanged');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $r, RosterShiftSwap $swap): RedirectResponse
    {
        $this->svc->reject($swap, $r->input('reason'));

        return back()->with('success', 'Swap rejected');
    }

    public function destroy(RosterShiftSwap $swap): RedirectResponse
    {
        $this->svc->delete($swap);

        return back()->with('success', 'Swap removed');
    }
}

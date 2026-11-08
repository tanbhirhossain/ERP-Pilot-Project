<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Informed\InformedRequest;
use Modules\HRM\Requests\StoreInformedRequestRequest;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\InformedRequestService;

class InformedRequestController extends Controller
{
    public function __construct(private readonly InformedRequestService $svc, private readonly EmployeeService $employees) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'informed_date', 'created_at'], ['status', 'employee_id', 'informed_type']);
        $page = $this->svc->paginate($dt);
        $mapped = $page->through(fn (InformedRequest $o) => ['id' => $o->id, 'employee_id' => $o->employee_id, 'employee' => $o->employee?->full_name ?? $o->employee_id, 'informed_date' => $o->informed_date?->toDateString(), 'informed_type' => $o->informed_type, 'expected_time' => $o->expected_time ? substr($o->expected_time, 0, 5) : null, 'reason' => $o->reason, 'status' => $o->status, 'created_at' => $o->created_at?->toIso8601String()]);

        return Inertia::render('HRM::Informed/Index', ['informed' => PaginatedResource::from($mapped)]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::Informed/Create', ['employeeOptions' => $this->employees->options(null, 200)]);
    }

    public function store(StoreInformedRequestRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.informed.index')->with('success', 'Informed notice submitted — Pending acknowledgement');
    }

    public function show(InformedRequest $informed): Response
    {
        $o = $this->svc->findOrFail($informed->id);

        return Inertia::render('HRM::Informed/Show', ['informed' => ['id' => $o->id, 'employee_id' => $o->employee_id, 'employee' => $o->employee?->full_name, 'informed_date' => $o->informed_date?->toDateString(), 'informed_type' => $o->informed_type, 'expected_time' => $o->expected_time, 'reason' => $o->reason, 'status' => $o->status, 'approver' => $o->approver?->name, 'approved_at' => $o->approved_at?->toIso8601String(), 'created_at' => $o->created_at?->toIso8601String()]]);
    }

    public function edit(InformedRequest $informed): Response
    {
        $o = $this->svc->findOrFail($informed->id);

        return Inertia::render('HRM::Informed/Create', ['employeeOptions' => $this->employees->options(null, 200), 'informed' => ['id' => $o->id, 'employee_id' => $o->employee_id, 'informed_date' => $o->informed_date?->toDateString(), 'informed_type' => $o->informed_type, 'expected_time' => $o->expected_time ? substr($o->expected_time, 0, 5) : '', 'reason' => $o->reason, 'status' => $o->status]]);
    }

    public function update(StoreInformedRequestRequest $req, InformedRequest $informed): RedirectResponse
    {
        $this->svc->update($informed, $req->validated());

        return redirect()->route('hrm.informed.index')->with('success', 'Informed updated');
    }

    public function acknowledge(InformedRequest $informed): RedirectResponse
    {
        try {
            $this->svc->acknowledge($informed);

            return back()->with('success', 'Informed acknowledged');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $r, InformedRequest $informed): RedirectResponse
    {
        $this->svc->reject($informed);

        return back()->with('success', 'Informed rejected');
    }

    public function destroy(InformedRequest $informed): RedirectResponse
    {
        $this->svc->delete($informed);

        return back()->with('success', 'Informed removed');
    }
}

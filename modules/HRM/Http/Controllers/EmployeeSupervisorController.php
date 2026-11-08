<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Employee\EmployeeSupervisor;
use Modules\HRM\Requests\StoreEmployeeSupervisorRequest;
use Modules\HRM\Requests\UpdateEmployeeSupervisorRequest;
use Modules\HRM\Services\EmployeeSupervisorService;

class EmployeeSupervisorController extends Controller
{
    public function __construct(private readonly EmployeeSupervisorService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'employee_id', 'supervisor_type', 'created_at'], ['employee_id', 'supervisor_type', 'is_active', 'is_primary']);
        $page = $this->svc->paginate($dt);
        $mapped = $page->through(fn (EmployeeSupervisor $m) => [
            'id' => $m->id,
            'employee_id' => $m->employee_id,
            'employee' => $m->employee?->full_name ?? $m->employee_id,
            'employee_obj' => $m->employee ? ['employee_id' => $m->employee->employee_id, 'full_name' => $m->employee->full_name] : null,
            'supervisor_kind' => $m->supervisor_employee_id ? 'employee' : 'user',
            'supervisor' => $m->supervisor_display,
            'supervisor_type' => $m->supervisor_type,
            'is_active' => $m->is_active,
            'is_primary' => $m->is_primary,
            'effective_from' => $m->effective_from?->toDateString(),
            'effective_to' => $m->effective_to?->toDateString(),
            'remarks' => $m->remarks,
            'created_at' => $m->created_at?->toIso8601String(),
        ]);

        return Inertia::render('HRM::Supervisors/Index', [
            'supervisors' => PaginatedResource::from($mapped),
        ]);
    }

    public function create(Request $r): Response
    {
        return Inertia::render('HRM::Supervisors/Create', [
            'employeeOptions' => $this->svc->employeeOptions($r->query('q'), 100),
            'userOptions' => $this->svc->userOptions($r->query('q'), 100),
            'supervisorTypes' => ['Supervisor', 'Manager', 'Incharge', 'Reporting Manager', 'Department Head'],
            'preselected_employee_id' => $r->query('employee_id'),
        ]);
    }

    public function store(StoreEmployeeSupervisorRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.supervisors.index')->with('success', 'Supervisor assigned.');
    }

    public function show(EmployeeSupervisor $supervisor): Response
    {
        $m = $this->svc->findOrFail($supervisor->id);

        return Inertia::render('HRM::Supervisors/Show', [
            'supervisor' => [
                'id' => $m->id,
                'employee_id' => $m->employee_id,
                'employee' => $m->employee ? ['employee_id' => $m->employee->employee_id, 'full_name' => $m->employee->full_name] : null,
                'supervisor_kind' => $m->supervisor_employee_id ? 'employee' : 'user',
                'supervisor_id' => $m->supervisor_id,
                'supervisor_employee_id' => $m->supervisor_employee_id,
                'supervisor' => $m->supervisor_display,
                'supervisor_type' => $m->supervisor_type,
                'is_active' => $m->is_active,
                'is_primary' => $m->is_primary,
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'remarks' => $m->remarks,
                'created_at' => $m->created_at?->toDateTimeString(),
            ],
        ]);
    }

    public function edit(EmployeeSupervisor $supervisor): Response
    {
        $m = $this->svc->findOrFail($supervisor->id);

        return Inertia::render('HRM::Supervisors/Edit', [
            'supervisor' => [
                'id' => $m->id,
                'employee_id' => $m->employee_id,
                'supervisor_kind' => $m->supervisor_employee_id ? 'employee' : 'user',
                'supervisor_id' => $m->supervisor_id,
                'supervisor_employee_id' => $m->supervisor_employee_id,
                'supervisor_type' => $m->supervisor_type,
                'is_active' => $m->is_active,
                'is_primary' => $m->is_primary,
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'remarks' => $m->remarks,
            ],
            'employeeOptions' => $this->svc->employeeOptions(null, 100),
            'userOptions' => $this->svc->userOptions(null, 100),
            'currentUser' => $m->supervisor ? ['value' => $m->supervisor->id, 'label' => $m->supervisor->name, 'description' => $m->supervisor->email] : null,
            'currentEmployee' => $m->supervisorEmployee ? ['value' => $m->supervisorEmployee->employee_id, 'label' => $m->supervisorEmployee->full_name] : null,
            'supervisorTypes' => ['Supervisor', 'Manager', 'Incharge', 'Reporting Manager', 'Department Head'],
        ]);
    }

    public function update(UpdateEmployeeSupervisorRequest $req, EmployeeSupervisor $supervisor): RedirectResponse
    {
        $this->svc->update($supervisor, $req->validated());

        return redirect()->route('hrm.supervisors.index')->with('success', 'Supervisor updated.');
    }

    public function destroy(EmployeeSupervisor $supervisor): RedirectResponse
    {
        $this->svc->delete($supervisor);

        return redirect()->route('hrm.supervisors.index')->with('success', 'Supervisor removed.');
    }
}

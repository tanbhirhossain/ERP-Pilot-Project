<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\EmployeeMovement;
use Modules\HRM\Services\MovementService;

/**
 * Career movements — transfers, promotions, demotions and grade changes.
 *
 * Approval is the consequential step: MovementService::approve() writes through
 * to the employee master and appends a salary-history row, so it is gated by
 * `hrm.movements.approve` rather than the plain manage permission.
 */
class EmployeeMovementController extends Controller
{
    public function __construct(private readonly MovementService $svc) {}

    public function index(Request $r): Response
    {
        $q = EmployeeMovement::query()
            ->with([
                'employee:id,employee_id,full_name',
                'fromDepartment:id,name',
                'toDepartment:id,name',
                'fromDesignation:id,name',
                'toDesignation:id,name',
            ])
            ->latest('effective_date');

        if ($status = $r->input('status')) {
            $q->where('status', $status);
        }
        if ($type = $r->input('movement_type')) {
            $q->where('movement_type', $type);
        }
        if ($employeeId = $r->input('employee_id')) {
            $q->where('employee_id', $employeeId);
        }

        return Inertia::render('HRM::Movements/Index', [
            'movements' => $q->paginate(20)->withQueryString(),
            'filters' => $r->only(['status', 'movement_type', 'employee_id']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::Movements/Create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'designations' => Designation::query()->orderBy('name')->get(['id', 'name', 'grade']),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'movement_type' => ['required', 'in:Transfer,Promotion,Demotion,Department Change,Designation Change,Grade Change,Location Transfer'],
            'effective_date' => ['required', 'date'],
            'to_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'to_designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'from_grade' => ['nullable', 'string', 'max:50'],
            'to_grade' => ['nullable', 'string', 'max:50'],
            'to_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $movement = $this->svc->create($data['employee_id'], $data);

        return redirect()
            ->route('hrm.movements.show', $movement->id)
            ->with('success', "Movement {$movement->movement_no} created as draft.");
    }

    public function show(EmployeeMovement $movement): Response
    {
        $movement->load([
            'employee:id,employee_id,full_name',
            'fromDepartment:id,name',
            'toDepartment:id,name',
            'fromDesignation:id,name',
            'toDesignation:id,name',
        ]);

        return Inertia::render('HRM::Movements/Show', ['movement' => $movement]);
    }

    public function submit(EmployeeMovement $movement): RedirectResponse
    {
        try {
            $this->svc->submit($movement);
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('success', "Movement {$movement->movement_no} submitted for approval.");
    }

    public function approve(EmployeeMovement $movement): RedirectResponse
    {
        try {
            $this->svc->approve($movement);
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('success', "Movement {$movement->movement_no} approved — employee master and salary history updated.");
    }

    public function reject(Request $r, EmployeeMovement $movement): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        try {
            $this->svc->reject($movement, $data['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('success', "Movement {$movement->movement_no} rejected.");
    }

    public function cancel(EmployeeMovement $movement): RedirectResponse
    {
        try {
            $this->svc->cancel($movement);
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('success', "Movement {$movement->movement_no} cancelled.");
    }
}

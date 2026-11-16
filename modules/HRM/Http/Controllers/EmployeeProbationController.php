<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\EmployeeProbation;
use Modules\HRM\Services\ProbationService;

/**
 * Probation & confirmation.
 *
 * Backed by ProbationService, which owns the lifecycle rules (no two open
 * probations per employee, extensions must move the deadline forward,
 * confirmation flips the employee master to Permanent).
 */
class EmployeeProbationController extends Controller
{
    public function __construct(private readonly ProbationService $svc) {}

    public function index(Request $r): Response
    {
        $q = EmployeeProbation::query()
            ->with(['employee:id,employee_id,full_name,department_id,designation_id', 'employee.department:id,name'])
            ->latest('probation_start');

        if ($status = $r->input('status')) {
            $q->where('status', $status);
        }
        if ($employeeId = $r->input('employee_id')) {
            $q->where('employee_id', $employeeId);
        }

        return Inertia::render('HRM::Probations/Index', [
            'probations' => $q->paginate(20)->withQueryString()->through(fn (EmployeeProbation $p): array => [
                'id' => $p->id,
                'employee_id' => $p->employee_id,
                'probation_start' => $p->probation_start?->toDateString(),
                'probation_end' => $p->probation_end?->toDateString(),
                'extended_till' => $p->extended_till?->toDateString(),
                'status' => $p->status,
                'remarks' => $p->remarks,
                'employee' => $p->employee ? [
                    'id' => $p->employee->id,
                    'employee_id' => $p->employee->employee_id,
                    'full_name' => $p->employee->full_name,
                    'department' => $p->employee->department?->name,
                ] : null,
            ]),
            // The service calls it dueCounts(); the page asked for stats() and answered HTTP 500.
            'stats' => $this->svc->dueCounts(),
            'filters' => $r->only(['status', 'employee_id']),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'probation_start' => ['required', 'date'],
            'probation_end' => ['required', 'date', 'after:probation_start'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            // create() takes the employee code and the payload separately. Handing it the whole array
            // was a TypeError on every submission, hidden until now behind the page never rendering.
            $probation = $this->svc->create($data['employee_id'], $data);
        } catch (\DomainException $e) {
            return back()->withErrors(['employee_id' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Probation opened for {$probation->employee_id} until {$data['probation_end']}.");
    }

    public function extend(Request $r, EmployeeProbation $probation): RedirectResponse
    {
        $data = $r->validate([
            'extended_till' => ['required', 'date', 'after:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->svc->extend($probation, $data['extended_till'], $data['remarks'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['extended_till' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Probation extended to {$data['extended_till']}.");
    }

    public function confirm(Request $r, EmployeeProbation $probation): RedirectResponse
    {
        $data = $r->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->svc->confirm($probation, $data['remarks'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['probation' => $e->getMessage()]);
        }

        return back()->with('success', "{$probation->employee_id} confirmed as permanent. Employee master updated.");
    }

    public function fail(Request $r, EmployeeProbation $probation): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        try {
            $this->svc->fail($probation, $data['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['probation' => $e->getMessage()]);
        }

        return back()->with('success', "Probation for {$probation->employee_id} marked as failed.");
    }
}

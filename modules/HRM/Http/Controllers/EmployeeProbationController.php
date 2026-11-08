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
            ->with(['employee:id,employee_id,full_name,department_id,designation_id'])
            ->latest('probation_start');

        if ($status = $r->input('status')) {
            $q->where('status', $status);
        }
        if ($employeeId = $r->input('employee_id')) {
            $q->where('employee_id', $employeeId);
        }

        return Inertia::render('HRM::Probations/Index', [
            'probations' => $q->paginate(20)->withQueryString(),
            'stats' => $this->svc->stats(),
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
            $probation = $this->svc->create($data);
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

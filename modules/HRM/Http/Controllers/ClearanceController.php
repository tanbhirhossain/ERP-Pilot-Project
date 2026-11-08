<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\ClearanceTask;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\ClearanceService;

/**
 * Exit clearance — the offboarding gate.
 *
 * A resignation cannot be settled until every clearance task is Cleared or
 * Waived; `isFullyCleared()` is the check the settlement flow relies on.
 */
class ClearanceController extends Controller
{
    public function __construct(private readonly ClearanceService $svc) {}

    public function index(Request $r): Response
    {
        $q = ClearanceTask::query()
            ->with(['employee:id,employee_id,full_name', 'clearedBy:id,name'])
            ->latest();

        if ($status = $r->input('status')) {
            $q->where('status', $status);
        }
        if ($department = $r->input('department')) {
            $q->where('department', $department);
        }
        if ($employeeId = $r->input('employee_id')) {
            $q->where('employee_id', $employeeId);
        }
        if ($resignationId = $r->input('resignation_id')) {
            $q->where('resignation_id', $resignationId);
        }

        return Inertia::render('HRM::Clearance/Index', [
            'tasks' => $q->paginate(25)->withQueryString(),
            'stats' => [
                'total' => ClearanceTask::count(),
                'pending' => ClearanceTask::where('status', 'Pending')->count(),
                'cleared' => ClearanceTask::where('status', 'Cleared')->count(),
                'rejected' => ClearanceTask::where('status', 'Rejected')->count(),
                'waived' => ClearanceTask::where('status', 'Waived')->count(),
            ],
            'departments' => ClearanceTask::query()->distinct()->orderBy('department')->pluck('department'),
            'filters' => $r->only(['status', 'department', 'employee_id', 'resignation_id']),
        ]);
    }

    public function clear(Request $r, ClearanceTask $clearanceTask): RedirectResponse
    {
        // A rejection or a waiver changes what the employee owes, so insist on a
        // written reason for the audit trail. A clean clearance may stay silent.
        $data = $r->validate([
            'status' => ['required', 'in:Cleared,Rejected,Waived'],
            'remarks' => $r->input('status') === 'Cleared'
                ? ['nullable', 'string', 'max:1000']
                : ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $task = $this->svc->clear($clearanceTask->id, $data['status'], $data['remarks'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        $fullyCleared = $this->svc->isFullyCleared($task->employee_id, $task->resignation_id);

        return back()->with(
            'success',
            $fullyCleared
                ? "Clearance task \"{$task->task_name}\" marked {$task->status} — employee is now fully cleared."
                : "Clearance task \"{$task->task_name}\" marked {$task->status}."
        );
    }

    public function forEmployee(Request $r, Employee $employee): Response
    {
        $resignationId = $r->input('resignation_id') ? (int) $r->input('resignation_id') : null;
        $summary = $this->svc->summary($employee->employee_id, $resignationId);

        return Inertia::render('HRM::Clearance/Index', [
            'tasks' => ClearanceTask::query()
                ->with(['employee:id,employee_id,full_name', 'clearedBy:id,name'])
                ->where('employee_id', $employee->employee_id)
                ->when($resignationId, fn ($q, $id) => $q->where('resignation_id', $id))
                ->latest()
                ->paginate(25),
            'stats' => [
                'total' => $summary['total'],
                'pending' => $summary['pending'],
                'cleared' => $summary['cleared'],
                'fully_cleared' => $summary['fully_cleared'],
            ],
            'employee' => ['employee_id' => $employee->employee_id, 'full_name' => $employee->full_name],
        ]);
    }
}

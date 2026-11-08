<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\ChecklistTemplate;
use Modules\HRM\Models\EmployeeChecklist;
use Modules\HRM\Services\ChecklistService;

/**
 * Onboarding / offboarding checklists.
 *
 * Templates hold the ordered task list; assigning one to an employee snapshots
 * a progress row per item so later template edits never rewrite history.
 */
class ChecklistController extends Controller
{
    public function __construct(private readonly ChecklistService $svc) {}

    public function index(Request $r): Response
    {
        // The page reads `templates.data` / `templates.total`, so this must stay
        // a paginator — a bare Collection renders an empty list. Its own page
        // key keeps it from colliding with the assignments paginator.
        $templates = ChecklistTemplate::query()
            ->withCount('items')
            ->when($category = $r->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(20, ['*'], 'templates_page')
            ->withQueryString();

        $assignments = EmployeeChecklist::query()
            ->with([
                'employee:id,employee_id,full_name',
                'template:id,name,category',
                'progress.item:id,title,responsible_role,sort_order,is_required',
            ])
            ->when($status = $r->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($employeeId = $r->input('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('HRM::Checklists/Index', [
            'templates' => $templates,
            'assignments' => $assignments,
            'filters' => $r->only(['category', 'status', 'employee_id']),
        ]);
    }

    public function storeTemplate(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:Onboarding,Offboarding'],
            'employment_type' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.responsible_role' => ['nullable', 'string', 'max:100'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.is_required' => ['nullable', 'boolean'],
        ]);

        $template = $this->svc->createTemplate($data);

        return back()->with('success', "Checklist template \"{$template->name}\" created with {$template->items->count()} items.");
    }

    public function assign(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'template_id' => ['required', 'integer', 'exists:hrm_checklist_templates,id'],
            'movement_id' => ['nullable', 'integer', 'exists:hrm_employee_movements,id'],
            'resignation_id' => ['nullable', 'integer', 'exists:employee_resignations,id'],
        ]);

        try {
            $checklist = $this->svc->assign(
                $data['employee_id'],
                (int) $data['template_id'],
                isset($data['movement_id']) ? (int) $data['movement_id'] : null,
                isset($data['resignation_id']) ? (int) $data['resignation_id'] : null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['template_id' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Checklist assigned to {$data['employee_id']} ({$checklist->progress->count()} tasks).");
    }

    /**
     * Set one checklist item's progress and recompute the parent percentage.
     */
    public function progress(Request $r, int $progressId): RedirectResponse
    {
        $data = $r->validate([
            'status' => ['required', 'in:Pending,Done,Skipped,N/A'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->svc->toggleProgress($progressId, $data['status'], $data['remarks'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Task marked {$data['status']}.");
    }

    /**
     * Seed the standard onboarding/offboarding templates. Safe to re-run.
     */
    public function seed(): RedirectResponse
    {
        $this->svc->seedDefaults();

        return back()->with('success', 'Default checklist templates seeded.');
    }
}

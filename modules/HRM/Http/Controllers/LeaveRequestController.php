<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Requests\StoreLeaveRequestRequest;
use Modules\HRM\Services\ApprovalEngine;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\LeaveBalanceService;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveTypeService;
use Modules\HRM\Services\LeaveWorkspaceService;

/**
 * Leave: the desk, the request list, the detail page and the approval actions.
 *
 * The module used to be one screen with four coloured counters on top of a table, and the approval
 * decision was two buttons inside a table row. The three jobs — "what needs a decision", "find an
 * application", "decide on this one" — now have their own pages, and the detail page shows what the
 * decision costs the employee's balance before it is taken.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $svc,
        private readonly LeaveTypeService $types,
        private readonly EmployeeService $employees,
        private readonly LeaveWorkspaceService $workspace,
        private readonly LeaveBalanceService $balances,
    ) {}

    /** The Leave Desk — the landing page of the module. */
    public function index(Request $r): Response
    {
        $year = (int) ($r->integer('year') ?: now()->format('Y'));

        return Inertia::render('HRM::Leave/Desk', [
            'overview' => $this->workspace->overview($year),
            'decisions' => $this->workspace->recentDecisions(10),
            'canApprove' => (bool) auth()->user()?->can('hrm.leaves.approve'),
        ]);
    }

    /** The searchable list of applications. */
    public function requests(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest(
            $r,
            ['id', 'from_date', 'created_at', 'days', 'status'],
            ['status', 'employee_id', 'leave_type_id', 'department_id', 'designation_id'],
        );
        $page = $this->svc->paginate($dt);
        $rows = $page->through(fn (LeaveRequest $leave): array => $this->workspace->requestRow($leave));

        return Inertia::render('HRM::Leave/Requests', [
            'leaves' => PaginatedResource::from($rows),
            'canApprove' => (bool) auth()->user()?->can('hrm.leaves.approve'),
            'filterDefs' => $this->filterDefs(),
        ]);
    }

    /** Leave liability and usage — the numbers the HR annual report is written from. */
    public function reports(Request $r): Response
    {
        $year = (int) ($r->integer('year') ?: now()->format('Y'));

        return Inertia::render('HRM::Leave/Reports', [
            'report' => $this->workspace->report($year),
            'canExport' => (bool) auth()->user()?->can('hrm.leaves.view'),
        ]);
    }

    /** The approval desk: every pending application, oldest first. */
    public function approvals(Request $r): Response
    {
        return Inertia::render('HRM::Leave/Approvals', [
            'queue' => $this->workspace->approvalQueue(),
            'canApprove' => (bool) auth()->user()?->can('hrm.leaves.approve'),
        ]);
    }

    public function create(Request $r): Response
    {
        return Inertia::render('HRM::Leave/Apply', [
            'employeeOptions' => $this->employees->options(null, 200),
            'leaveTypeOptions' => $this->types->options(),
            'presetEmployee' => $r->string('employee_id')->toString() ?: null,
        ]);
    }

    /**
     * The balance register.
     *
     * The old page was an empty state until you picked one employee. This one opens on everybody —
     * that is the question an HR desk actually arrives with — and narrows to one person's card.
     */
    public function balances(Request $r): Response
    {
        $data = $r->validate([
            'employee_id' => ['nullable', 'exists:employees,employee_id'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $year = (int) ($data['year'] ?? now()->format('Y'));
        $employeeId = $data['employee_id'] ?? null;

        $focused = null;
        if ($employeeId !== null) {
            $employee = \Modules\HRM\Models\Employee\Employee::query()
                ->with(['department:id,name', 'designation:id,name'])
                ->where('employee_id', $employeeId)
                ->first();

            $focused = [
                'employee_id' => $employeeId,
                'employee' => $employee?->full_name ?: $employeeId,
                'department' => $employee?->department?->name,
                'designation' => $employee?->designation?->name,
                'balances' => $this->balances->balanceForAllTypes($employeeId, $year),
                'history' => LeaveRequest::query()
                    ->with('leaveType:id,name,code,color')
                    ->where('employee_id', $employeeId)
                    ->whereYear('from_date', $year)
                    ->orderByDesc('from_date')
                    ->limit(40)
                    ->get()
                    ->map(fn (LeaveRequest $l): array => $this->workspace->requestRow($l))
                    ->all(),
            ];
        }

        return Inertia::render('HRM::Leave/Balances', [
            'register' => $this->balances->register(
                $year,
                $data['search'] ?? null,
                null,
                (int) ($data['per_page'] ?? 25),
                (int) ($data['page'] ?? 1),
            ),
            'focused' => $focused,
            'employeeOptions' => $this->employees->options(null, 200),
            'year' => $year,
            'longLeaveThreshold' => LeaveBalanceService::LONG_LEAVE_DAYS,
        ]);
    }

    /** Live balance quote for the application form (JSON, called as the dates change). */
    public function balance(Request $r): JsonResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'is_half_day' => ['nullable', 'boolean'],
            'ignore_leave_id' => ['nullable', 'integer', 'exists:leave_requests,id'],
        ]);

        $year = isset($data['year'])
            ? (int) $data['year']
            : (! empty($data['from_date']) ? (int) Carbon::parse($data['from_date'])->year : null);

        $balance = $this->balances->balance($data['employee_id'], (int) $data['leave_type_id'], $year);

        if (! empty($data['ignore_leave_id'])) {
            $old = LeaveRequest::query()
                ->whereKey($data['ignore_leave_id'])
                ->where('employee_id', $data['employee_id'])
                ->where('leave_type_id', $data['leave_type_id'])
                ->where('status', 'Pending')
                ->first();
            if ($old !== null) {
                $balance['available'] += (float) $old->days;
                $balance['pending'] = max(0, (float) $balance['pending'] - (float) $old->days);
            }
        }

        if (! empty($data['from_date']) && ! empty($data['to_date'])) {
            try {
                $preview = $this->balances->previewDays(
                    $data['employee_id'],
                    (int) $data['leave_type_id'],
                    $data['from_date'],
                    $data['to_date'],
                    (bool) ($data['is_half_day'] ?? false),
                );
                $balance['requested_days'] = $preview['days'];
                $balance['chargeable'] = $preview['chargeable'];
                $balance['excluded'] = $preview['excluded'];
                $balance['dates'] = $preview['dates'];
                $balance['available_after_request'] = round($balance['available'] - $preview['days'], 2);
            } catch (\Throwable $e) {
                $balance['quote_error'] = $e->getMessage();
            }
        }

        return response()->json($balance);
    }

    public function store(StoreLeaveRequestRequest $req): RedirectResponse
    {
        $leave = $this->svc->create($req->validated());

        return redirect()->route('hrm.leaves.show', $leave->id)
            ->with('success', "Leave application #{$leave->id} submitted for {$leave->days} day(s). It is pending approval.");
    }

    public function show(LeaveRequest $leave): Response
    {
        $request = $this->svc->findOrFail($leave->id);

        return Inertia::render('HRM::Leave/Show', [
            'detail' => $this->workspace->requestDetail($request),
            'canApprove' => (bool) auth()->user()?->can('hrm.leaves.approve'),
            'canManage' => (bool) auth()->user()?->can('hrm.leaves.manage'),
        ]);
    }

    public function edit(LeaveRequest $leave): Response
    {
        $request = $this->svc->findOrFail($leave->id);

        return Inertia::render('HRM::Leave/Apply', [
            'employeeOptions' => $this->employees->options(null, 200),
            'leaveTypeOptions' => $this->types->options(),
            'presetEmployee' => $request->employee_id,
            'leave' => [
                'id' => $request->id,
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'from_date' => $request->from_date?->toDateString(),
                'to_date' => $request->to_date?->toDateString(),
                'is_half_day' => $request->is_half_day,
                'reason' => $request->reason,
                'status' => $request->status,
            ],
        ]);
    }

    public function update(StoreLeaveRequestRequest $req, LeaveRequest $leave): RedirectResponse
    {
        $this->svc->update($leave, $req->validated());

        return redirect()->route('hrm.leaves.show', $leave->id)->with('success', 'Leave application updated. It is still pending approval.');
    }

    public function approve(Request $r, LeaveRequest $leave): RedirectResponse
    {
        abort_unless(auth()->user()?->can('hrm.leaves.approve'), 403);
        $data = $r->validate(['comments' => ['nullable', 'string', 'max:1000']]);

        try {
            // Under an active chain the desk decides through the engine — the same
            // staged write, plus stage-exact authorization and the next-desk ping.
            $result = $this->chained()
                ? app(ApprovalEngine::class)->approveLeave($r->user(), $leave, $data['comments'] ?? null)
                : $this->svc->approve($leave, $data['comments'] ?? null);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $result->status === 'Approved'
            ? "Approved: {$result->days} day(s) for {$result->employee_id}. The attendance calendar now shows the leave."
            : 'Approval stage recorded. The application is still pending the next stage.');
    }

    public function reject(Request $r, LeaveRequest $leave): RedirectResponse
    {
        abort_unless(auth()->user()?->can('hrm.leaves.approve'), 403);
        // A rejection without a reason is not an auditable HR decision.
        $data = $r->validate(['comments' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $this->chained()
                ? app(ApprovalEngine::class)->rejectLeave($r->user(), $leave, $data['comments'])
                : $this->svc->reject($leave, $data['comments']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Application rejected and the reason is recorded on its trail. Any attendance days it had marked were released.');
    }

    /** Whether the leave chain is configured: only then do web decisions walk it. */
    private function chained(): bool
    {
        return app(ApprovalEngine::class)->steps(ApprovalChain::LEAVE) !== null;
    }

    public function destroy(LeaveRequest $leave): RedirectResponse
    {
        $this->svc->delete($leave);

        return back()->with('success', 'Leave application removed. Any attendance days it had marked were released.');
    }

    private function filterDefs(): array
    {
        return [
            ['key' => 'department_id', 'label' => 'Department', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/departments', 'placeholder' => 'Filter department'],
            ['key' => 'designation_id', 'label' => 'Designation', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/designations', 'placeholder' => 'Filter designation'],
            ['key' => 'employee_id', 'label' => 'Employee', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/employees', 'placeholder' => 'Search employee…'],
            ['key' => 'leave_type_id', 'label' => 'Leave type', 'kind' => 'select', 'placeholder' => 'All types', 'options' => LeaveType::query()->orderBy('name')->get()->map(fn (LeaveType $t): array => ['value' => (string) $t->id, 'label' => $t->name])->all()],
            ['key' => 'status', 'label' => 'Status', 'kind' => 'select', 'placeholder' => 'All statuses', 'options' => [
                ['value' => 'Pending', 'label' => 'Pending'],
                ['value' => 'Approved', 'label' => 'Approved'],
                ['value' => 'Rejected', 'label' => 'Rejected'],
            ]],
        ];
    }
}

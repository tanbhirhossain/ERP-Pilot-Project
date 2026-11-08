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
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Requests\StoreLeaveRequestRequest;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveTypeService;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveRequestService $svc, private readonly LeaveTypeService $types, private readonly EmployeeService $employees) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest(
            $r,
            ['id', 'from_date', 'created_at'],
            ['status', 'employee_id', 'leave_type_id', 'department_id', 'designation_id'],
        );
        $page = $this->svc->paginate($dt);
        $mapped = $page->through(fn (LeaveRequest $leave) => [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee' => $leave->employee?->full_name ?? $leave->employee_id,
            'leave_type' => $leave->leaveType?->name,
            'leave_type_color' => $leave->leaveType?->color,
            'code' => $leave->leaveType?->code,
            'from_date' => $leave->from_date?->toDateString(),
            'to_date' => $leave->to_date?->toDateString(),
            'days' => $leave->days,
            'is_half_day' => $leave->is_half_day,
            'status' => $leave->status,
            'created_at' => $leave->created_at?->toIso8601String(),
        ]);

        return Inertia::render('HRM::LeaveRequests/Index', [
            'leaves' => PaginatedResource::from($mapped),
            'canApprove' => (bool) (auth()->user()?->can('hrm.leaves.approve')),
            'filterDefs' => [
                [
                    'key' => 'department_id',
                    'label' => 'Department',
                    'kind' => 'endpoint',
                    'endpoint' => '/hrm/api/options/departments',
                    'placeholder' => 'Filter department',
                ],
                [
                    'key' => 'designation_id',
                    'label' => 'Designation',
                    'kind' => 'endpoint',
                    'endpoint' => '/hrm/api/options/designations',
                    'placeholder' => 'Filter designation',
                ],
                [
                    'key' => 'employee_id',
                    'label' => 'Employee',
                    'kind' => 'endpoint',
                    'endpoint' => '/hrm/api/options/employees',
                    'placeholder' => 'Search employee…',
                ],
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'Pending', 'label' => 'Pending'],
                        ['value' => 'Approved', 'label' => 'Approved'],
                        ['value' => 'Rejected', 'label' => 'Rejected'],
                    ],
                    'placeholder' => 'All statuses',
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::LeaveRequests/Create', ['employeeOptions' => $this->employees->options(null, 200), 'leaveTypeOptions' => $this->types->options()]);
    }

    public function balances(Request $r): Response
    {
        $data = $r->validate(['employee_id' => ['nullable', 'exists:employees,employee_id'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $employeeId = $data['employee_id'] ?? null;
        $year = (int) ($data['year'] ?? now()->format('Y'));
        $rows = $employeeId ? LeaveType::where('is_active', true)->orderBy('name')->get()->map(fn ($t) => $this->svc->balance($employeeId, $t->id, $year))->values() : collect();

        return Inertia::render('HRM::LeaveRequests/Balances', ['employeeOptions' => $this->employees->options(null, 200), 'employeeId' => $employeeId, 'year' => $year, 'balances' => $rows]);
    }

    public function store(StoreLeaveRequestRequest $req): RedirectResponse
    {
        $leave = $this->svc->create($req->validated());

        return redirect()->route('hrm.leaves.show', $leave->id)->with('success', 'Leave application submitted — approval pending. Application slip generated below.');
    }

    public function balance(Request $r): JsonResponse
    {
        $data = $r->validate(['employee_id' => ['required', 'exists:employees,employee_id'], 'leave_type_id' => ['required', 'exists:leave_types,id'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'], 'is_half_day' => ['nullable', 'boolean'], 'ignore_leave_id' => ['nullable', 'integer', 'exists:leave_requests,id']]);
        $year = isset($data['year']) ? (int) $data['year'] : (! empty($data['from_date']) ? (int) Carbon::parse($data['from_date'])->year : null);
        $balance = $this->svc->balance($data['employee_id'], (int) $data['leave_type_id'], $year);
        if (! empty($data['ignore_leave_id'])) {
            $old = LeaveRequest::whereKey($data['ignore_leave_id'])->where('employee_id', $data['employee_id'])->where('leave_type_id', $data['leave_type_id'])->where('status', 'Pending')->first();
            if ($old) {
                $balance['available'] += (float) $old->days;
                $balance['pending'] = max(0, (float) $balance['pending'] - (float) $old->days);
            }
        } if (! empty($data['from_date']) && ! empty($data['to_date'])) {
            try {
                $days = $this->svc->estimateDays($data['employee_id'], (int) $data['leave_type_id'], $data['from_date'], $data['to_date'], (bool) ($data['is_half_day'] ?? false));
                $balance['requested_days'] = $days;
                $balance['available_after_request'] = round(max(0, $balance['available'] - $days), 2);
            } catch (\Throwable $e) {
                $balance['quote_error'] = $e->getMessage();
            }
        }

        return response()->json($balance);
    }

    public function show(LeaveRequest $leave): Response
    {
        $request = $this->svc->findOrFail($leave->id);

        return Inertia::render('HRM::LeaveRequests/Show', [
            'canApprove' => (bool) (auth()->user()?->can('hrm.leaves.approve')),
            'leave' => [
                'id' => $request->id,
                'employee_id' => $request->employee_id,
                'employee' => $request->employee?->full_name,
                'leave_type' => $request->leaveType?->name,
                'leave_type_id' => $request->leave_type_id,
                'from_date' => $request->from_date?->toDateString(),
                'to_date' => $request->to_date?->toDateString(),
                'days' => $request->days,
                'is_half_day' => $request->is_half_day,
                'reason' => $request->reason,
                'status' => $request->status,
                'approver' => $request->approver?->name,
                'approved_at' => $request->approved_at?->toIso8601String(),
                'created_at' => $request->created_at?->toIso8601String(),
                'approval_history' => $this->svc->approvalHistory($request->id),
                'next_approval_stage' => $request->status === 'Pending'
                    ? $this->svc->nextApprovalStage($request)
                    : null,
                'leave_balance' => $this->svc->balance(
                    $request->employee_id,
                    (int) $request->leave_type_id,
                    (int) $request->from_date->format('Y'),
                ),
            ],
        ]);
    }

    public function edit(LeaveRequest $leave): Response
    {
        $request = $this->svc->findOrFail($leave->id);

        return Inertia::render('HRM::LeaveRequests/Create', [
            'employeeOptions' => $this->employees->options(null, 200),
            'leaveTypeOptions' => $this->types->options(),
            'canApprove' => (bool) (auth()->user()?->can('hrm.leaves.approve')),
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

        return redirect()->route('hrm.leaves.index')->with('success', 'Leave updated');
    }

    public function approve(LeaveRequest $leave): RedirectResponse
    {
        abort_unless(auth()->user()?->can('hrm.leaves.approve'), 403);
        try {
            $result = $this->svc->approve($leave);

            return back()->with('success', $result->status === 'Approved' ? 'Leave fully approved' : 'Approval stage completed; request remains pending for the next stage.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $r, LeaveRequest $leave): RedirectResponse
    {
        abort_unless(auth()->user()?->can('hrm.leaves.approve'), 403);
        $data = $r->validate(['comments' => ['nullable', 'string', 'max:1000']]);
        try {
            $this->svc->reject($leave, $data['comments'] ?? null);

            return back()->with('success', 'Leave rejected');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(LeaveRequest $leave): RedirectResponse
    {
        $this->svc->delete($leave);

        return back()->with('success', 'Leave removed');
    }
}

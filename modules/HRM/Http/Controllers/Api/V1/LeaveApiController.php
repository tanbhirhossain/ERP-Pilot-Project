<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveWorkspaceService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee leave self-service.
 *
 * GET    /api/v1/leaves/types                 active leave types
 * GET    /api/v1/leaves/balances?year=        my balances (all types)
 * GET    /api/v1/leaves/estimate?type=&from=&to=&half=  days + availability check
 * GET    /api/v1/leaves?status=&year=&type_id= my applications (tracking list)
 * POST   /api/v1/leaves                       apply
 * GET    /api/v1/leaves/{id}                  track one: detail + workflow stage + history
 * DELETE /api/v1/leaves/{id}                  withdraw a Pending application
 *
 * All writes go through LeaveRequestService, so the mobile app gets the same
 * overlap guard, balance guard and workflow snapshot the web form gets.
 */
class LeaveApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(
        private readonly LeaveRequestService $leaves,
        private readonly LeaveWorkspaceService $workspace,
    ) {}

    public function types(): JsonResponse
    {
        $types = LeaveType::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'days_per_year', 'is_paid', 'color', 'description']);

        return ApiResponse::ok($types, 'Leave types.');
    }

    public function balances(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return ApiResponse::ok(
            $this->leaves->balancesFor($employee->employee_id, $data['year'] ?? null),
            'Leave balances.'
        );
    }

    public function estimate(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'is_half_day' => ['nullable', 'boolean'],
        ]);

        $days = $this->leaves->estimateDays(
            $employee->employee_id,
            (int) $data['leave_type_id'],
            $data['from_date'],
            $data['to_date'],
            (bool) ($data['is_half_day'] ?? false),
        );

        $balance = $this->leaves->balance(
            $employee->employee_id,
            (int) $data['leave_type_id'],
            (int) date('Y', strtotime($data['from_date'])),
        );

        return ApiResponse::ok([
            'days' => $days,
            'available' => $balance['available'] ?? 0,
            'sufficient' => $days <= (float) ($balance['available'] ?? 0),
        ], 'Leave estimate.');
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LeaveRequest::where('employee_id', $employee->employee_id)
            ->with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['year'])) {
            $query->whereYear('from_date', (int) $data['year']);
        }

        if (! empty($data['leave_type_id'])) {
            $query->where('leave_type_id', (int) $data['leave_type_id']);
        }

        $page = $query->paginate($data['per_page'] ?? 15)->through(
            fn (LeaveRequest $leave): array => $this->workspace->requestRow($leave)
        );

        return ApiResponse::paginated($page, 'My leave applications.');
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'is_half_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $leave = $this->leaves->create($data + ['employee_id' => $employee->employee_id]);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $leave->load(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color']);

        return ApiResponse::ok($this->workspace->requestRow($leave), 'Leave application submitted.', [], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $leave = LeaveRequest::where('employee_id', $employee->employee_id)->find($id);

        if ($leave === null) {
            return ApiResponse::error('Leave application not found.', 404);
        }

        return ApiResponse::ok([
            'detail' => $this->workspace->requestDetail($leave),
            'history' => $this->leaves->approvalHistory($leave->id),
            'current_stage' => $this->leaves->nextApprovalStage($leave),
        ], 'Leave application.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $leave = LeaveRequest::where('employee_id', $employee->employee_id)->find($id);

        if ($leave === null) {
            return ApiResponse::error('Leave application not found.', 404);
        }

        if ($leave->status !== 'Pending') {
            return ApiResponse::error("Only a Pending application can be withdrawn (this one is {$leave->status}).", 422);
        }

        try {
            $this->leaves->delete($leave);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::ok(null, 'Leave application withdrawn.');
    }
}

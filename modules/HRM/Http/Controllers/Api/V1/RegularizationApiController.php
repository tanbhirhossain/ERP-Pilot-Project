<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\HRM\Services\RegularizationService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee attendance-regularization self-service.
 *
 * GET  /api/v1/regularizations/types        requestable types
 * GET  /api/v1/regularizations?status=      my requests
 * POST /api/v1/regularizations              apply (re-files a rejected one)
 * GET  /api/v1/regularizations/{id}         one request + reviewer
 * POST /api/v1/regularizations/{id}/cancel  cancel a Pending request
 *
 * Writes go through RegularizationService, so the one-row-per-(employee, date,
 * type) reopen rule behaves exactly like the web desk.
 */
class RegularizationApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(private readonly RegularizationService $regularizations) {}

    #[ApiEndpoint(
        summary: 'Regularization types',
        description: 'The 8 request types the apply form offers (Missing In/Out, Wrong Shift, …).',
        response: 'MobileEnvelope',
    )]
    public function types(): JsonResponse
    {
        return ApiResponse::ok(AttendanceRegularization::TYPES, 'Regularization types.');
    }

    #[ApiEndpoint(
        summary: 'My regularization requests',
        description: 'Newest first, with the reviewer once decided.',
        query: [
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Pending, Approved, Rejected.'],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AttendanceRegularization::where('employee_id', $employee->employee_id)
            ->with('reviewer:id,name')
            ->orderByDesc('duty_date')
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 15), 'My regularization requests.');
    }

    #[ApiEndpoint(
        summary: 'Request attendance regularization',
        description: 'Duty date must be today or earlier; the reason is required. One open request per date — a duplicate answers 422.',
        request: 'MobileRegularizationApply',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'duty_date' => ['required', 'date', 'before_or_equal:today'],
            'regularization_type' => ['required', Rule::in(AttendanceRegularization::TYPES)],
            'assigned_shift_id' => ['nullable', 'integer', 'exists:roster_shifts,id'],
            'requested_in_time' => ['nullable', 'date_format:H:i'],
            'requested_out_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $reg = $this->regularizations->request($data + ['employee_id' => $employee->employee_id]);
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $reg->load('reviewer:id,name');

        return ApiResponse::ok($reg, 'Regularization request submitted.', [], 201);
    }

    #[ApiEndpoint(
        summary: 'Read one regularization request',
        description: 'Includes the reviewer once decided. Another employee\'s id answers 404.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $reg = AttendanceRegularization::where('employee_id', $employee->employee_id)
            ->with('reviewer:id,name')
            ->find($id);

        if ($reg === null) {
            return ApiResponse::error('Regularization request not found.', 404);
        }

        return ApiResponse::ok($reg, 'Regularization request.');
    }

    #[ApiEndpoint(
        summary: 'Cancel a pending request',
        description: 'Pending only — anything already decided answers 422.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function cancel(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $reg = AttendanceRegularization::where('employee_id', $employee->employee_id)->find($id);

        if ($reg === null) {
            return ApiResponse::error('Regularization request not found.', 404);
        }

        if ($reg->status !== 'Pending') {
            return ApiResponse::error("Only a Pending request can be cancelled (this one is {$reg->status}).", 422);
        }

        try {
            $reg = $this->regularizations->cancel($reg);
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::ok($reg->fresh(), 'Regularization request cancelled.');
    }
}

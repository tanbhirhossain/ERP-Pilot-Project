<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Models\InformedRequest;
use Modules\HRM\Services\InformedRequestService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee informed-request self-service ("I will be late / on field work").
 *
 * GET  /api/v1/informed/types     requestable types
 * GET  /api/v1/informed?status=   my requests
 * POST /api/v1/informed           apply
 * GET  /api/v1/informed/{id}      one request
 */
class InformedApiController extends Controller
{
    use ResolvesApiEmployee;

    public const TYPES = ['Late Coming', 'Early Out', 'Absent Informed', 'Field Work', 'On Duty', 'Other'];

    public function __construct(private readonly InformedRequestService $informed) {}

    #[ApiEndpoint(
        summary: 'Informed request types',
        description: 'The 6 types the apply form offers (Late Coming, Field Work, On Duty, …).',
        response: 'MobileEnvelope',
    )]
    public function types(): JsonResponse
    {
        return ApiResponse::ok(self::TYPES, 'Informed request types.');
    }

    #[ApiEndpoint(
        summary: 'My informed requests',
        description: 'Advance notices filed with the office, newest first.',
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

        $query = InformedRequest::where('employee_id', $employee->employee_id)
            ->orderByDesc('informed_date')
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 15), 'My informed requests.');
    }

    #[ApiEndpoint(
        summary: 'File an informed request',
        description: 'Late coming, field work, on-duty and the rest — an advance notice, not an approval.',
        request: 'MobileInformedApply',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'informed_date' => ['required', 'date'],
            'informed_type' => ['required', Rule::in(self::TYPES)],
            'expected_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $row = $this->informed->create($data + ['employee_id' => $employee->employee_id]);
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        return ApiResponse::ok($row->fresh(), 'Informed request submitted.', [], 201);
    }

    #[ApiEndpoint(
        summary: 'Read one informed request',
        description: 'Another employee\'s id answers 404.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $row = InformedRequest::where('employee_id', $employee->employee_id)->find($id);

        if ($row === null) {
            return ApiResponse::error('Informed request not found.', 404);
        }

        return ApiResponse::ok($row, 'Informed request.');
    }
}

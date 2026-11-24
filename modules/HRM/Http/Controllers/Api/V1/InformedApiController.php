<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

    public function types(): JsonResponse
    {
        return ApiResponse::ok(self::TYPES, 'Informed request types.');
    }

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

<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Services\EmployeeResignationService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee resignation self-service.
 *
 * GET  /api/v1/resignations   my resignation request(s) + decision status
 * POST /api/v1/resignations   apply (one pending request at a time)
 *
 * Goes through EmployeeResignationService, so the clearance workflow the web
 * desk triggers fires for mobile applications too.
 */
class ResignationApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(private readonly EmployeeResignationService $resignations) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $rows = EmployeeResignation::where('employee_id', $employee->employee_id)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::ok($rows, 'My resignation requests.');
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'last_working_date' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $row = $this->resignations->request(
                $data + ['employee_id' => $employee->employee_id],
                $request->user()?->id,
            );
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::ok($row->fresh(), 'Resignation request submitted.', [], 201);
    }
}

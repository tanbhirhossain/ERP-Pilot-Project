<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Models\Certificate\CertificateRequest;
use Modules\HRM\Services\CertificateRequestService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee certificate self-service (Salary / NOC / Experience).
 *
 * GET  /api/v1/certificates/types       certificate types
 * GET  /api/v1/certificates?status=     my requests
 * POST /api/v1/certificates             request (purpose required)
 * GET  /api/v1/certificates/{id}        one request + issuer
 * POST /api/v1/certificates/{id}/cancel cancel a Pending request
 */
class CertificateApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(private readonly CertificateRequestService $certificates) {}

    public function types(): JsonResponse
    {
        return ApiResponse::ok(CertificateRequest::TYPES, 'Certificate types.');
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

        $query = CertificateRequest::where('employee_id', $employee->employee_id)
            ->with('issuer:id,name')
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 15), 'My certificate requests.');
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'certificate_type' => ['required', Rule::in(CertificateRequest::TYPES)],
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $row = $this->certificates->request(
                $data + ['employee_id' => $employee->employee_id],
                $request->user()?->id,
            );
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::ok($row->fresh(), 'Certificate request submitted.', [], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $row = CertificateRequest::where('employee_id', $employee->employee_id)
            ->with('issuer:id,name')
            ->find($id);

        if ($row === null) {
            return ApiResponse::error('Certificate request not found.', 404);
        }

        return ApiResponse::ok($row, 'Certificate request.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $row = CertificateRequest::where('employee_id', $employee->employee_id)->find($id);

        if ($row === null) {
            return ApiResponse::error('Certificate request not found.', 404);
        }

        try {
            $row = $this->certificates->cancel($row);
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::ok($row, 'Certificate request cancelled.');
    }
}

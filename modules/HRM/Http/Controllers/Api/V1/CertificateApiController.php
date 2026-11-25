<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Interfaces\Certificate\CertificateRequestRepositoryInterface;
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

    public function __construct(
        private readonly CertificateRequestService $certificates,
        private readonly CertificateRequestRepositoryInterface $rows,
    ) {}

    #[ApiEndpoint(
        summary: 'Certificate types',
        description: 'Salary, NOC and Experience — the catalogue the request form offers.',
        response: 'MobileEnvelope',
    )]
    public function types(): JsonResponse
    {
        return ApiResponse::ok($this->rows->types(), 'Certificate types.');
    }

    #[ApiEndpoint(
        summary: 'My certificate requests',
        description: 'Newest first, with the issuer once issued.',
        query: [
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Pending, Issued, Rejected, Cancelled.'],
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

        return ApiResponse::paginated(
            $this->rows->paginateForEmployee($employee->employee_id, $data['status'] ?? null, $data['per_page'] ?? 15),
            'My certificate requests.'
        );
    }

    #[ApiEndpoint(
        summary: 'Request a certificate',
        description: 'The purpose is required (min 5 characters) — it is printed on the certificate.',
        request: 'MobileCertificateApply',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'certificate_type' => ['required', Rule::in($this->rows->types())],
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

    #[ApiEndpoint(
        summary: 'Read one certificate request',
        description: 'Includes the issuer once issued. Another employee\'s id answers 404.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $row = $this->rows->findForEmployee($id, $employee->employee_id, true);

        if ($row === null) {
            return ApiResponse::error('Certificate request not found.', 404);
        }

        return ApiResponse::ok($row, 'Certificate request.');
    }

    #[ApiEndpoint(
        summary: 'Cancel a pending request',
        description: 'Pending only — an issued certificate answers 422.',
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

        $row = $this->rows->findForEmployee($id, $employee->employee_id);

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

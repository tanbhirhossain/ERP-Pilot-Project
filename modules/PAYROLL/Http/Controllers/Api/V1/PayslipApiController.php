<?php

namespace Modules\PAYROLL\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSlipRepositoryInterface;

/**
 * Employee payslip self-service.
 *
 * GET /api/v1/payslips?year=   my slips, newest first (with sheet status)
 * GET /api/v1/payslips/{id}    one slip with the full earnings/deductions
 *                              breakdown (same row the web self-service shows)
 */
class PayslipApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(private readonly PayrollSlipRepositoryInterface $slips) {}

    #[ApiEndpoint(
        summary: 'My payslips',
        description: 'Newest first, each with its sheet status so the app can badge unreleased months.',
        query: [
            ['name' => 'year', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100]],
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
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated(
            $this->slips->paginateForEmployee(
                $employee->employee_id,
                isset($data['year']) ? (int) $data['year'] : null,
                $data['per_page'] ?? 12,
            ),
            'My payslips.'
        );
    }

    #[ApiEndpoint(
        summary: 'Read one payslip, full breakdown',
        description: 'Earnings, deductions and net for the slip. Another employee\'s id answers 404.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $slip = $this->slips->findForEmployee($id, $employee->employee_id);

        if ($slip === null) {
            return ApiResponse::error('Payslip not found.', 404);
        }

        return ApiResponse::ok($slip, 'Payslip.');
    }
}

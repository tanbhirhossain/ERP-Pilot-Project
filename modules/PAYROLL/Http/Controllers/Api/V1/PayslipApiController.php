<?php

namespace Modules\PAYROLL\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Models\PayrollSlip;

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

        $query = PayrollSlip::where('employee_id', $employee->employee_id)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('pay_year')
            ->orderByDesc('pay_month')
            ->orderByDesc('id');

        if (! empty($data['year'])) {
            $query->where('pay_year', (int) $data['year']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 12), 'My payslips.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $slip = PayrollSlip::where('employee_id', $employee->employee_id)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->find($id);

        if ($slip === null) {
            return ApiResponse::error('Payslip not found.', 404);
        }

        return ApiResponse::ok($slip, 'Payslip.');
    }
}

<?php

namespace Modules\PAYROLL\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Services\PayrollLoanService;

/**
 * Employee loan self-service (interest-free advances, same flow as the web).
 *
 * GET  /api/v1/loans       my loans with recovery position + approval trail
 * POST /api/v1/loans       apply (amount, 1–120 installments, first due, purpose)
 * GET  /api/v1/loans/{id}  one loan with its monthly schedule + approvals
 *
 * Applies through PayrollLoanService, so the salary-record check, the 0%
 * schedule math and the configured approval steps all behave like the desk.
 */
class LoanApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(private readonly PayrollLoanService $loans) {}

    #[ApiEndpoint(
        summary: 'My loans, recovery and trail',
        description: 'Every loan with its instalment schedule and approval trail.',
        response: 'MobileEnvelope',
    )]
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $rows = PayrollLoan::where('employee_id', $employee->employee_id)
            ->with(['installments', 'approvals'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PayrollLoan $loan): array => $this->loanRow($loan));

        return ApiResponse::ok($rows->values()->all(), 'My loans.');
    }

    #[ApiEndpoint(
        summary: 'Apply for a loan',
        description: 'Goes to the approval queue; the purpose (min 5 characters) travels with it.',
        request: 'MobileLoanApply',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        // Same rules as the payroll desk's store(), minus employee_id (always own).
        $data = $request->validate([
            'principal_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'installment_count' => ['required', 'integer', 'between:1,120'],
            'first_due_date' => ['required', 'date'],
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $loan = $this->loans->request(
                $data + ['employee_id' => $employee->employee_id],
                $request->user()?->id,
            );
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $loan->load(['installments', 'approvals']);

        return ApiResponse::ok($this->loanRow($loan), 'Loan request submitted for approval.', [], 201);
    }

    #[ApiEndpoint(
        summary: 'Read one loan with its schedule',
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

        $loan = PayrollLoan::where('employee_id', $employee->employee_id)
            ->with(['installments', 'approvals'])
            ->find($id);

        if ($loan === null) {
            return ApiResponse::error('Loan not found.', 404);
        }

        return ApiResponse::ok($this->loanRow($loan), 'Loan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loanRow(PayrollLoan $loan): array
    {
        $installments = $loan->installments;

        $paid = (float) $installments->where('status', 'paid')->sum('amount');
        $principal = (float) $loan->principal_amount;

        return [
            'id' => $loan->id,
            'principal_amount' => $principal,
            'installment_count' => (int) $loan->installment_count,
            'installment_amount' => (float) $loan->installment_amount,
            'first_due_date' => $loan->first_due_date?->toDateString(),
            'purpose' => $loan->purpose,
            'status' => $loan->status,
            'paid_amount' => round($paid, 2),
            'outstanding_amount' => round(max(0, $principal - $paid), 2),
            'installments_paid' => $installments->where('status', 'paid')->count(),
            'installments_total' => $installments->count(),
            'requested_at' => $loan->created_at?->toDateTimeString(),
            'approved_at' => $loan->approved_at?->toDateTimeString(),
            'installments' => $installments->values()->all(),
            'approvals' => $loan->approvals->values()->all(),
        ];
    }
}

<?php

namespace Modules\PAYROLL\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Models\PfAccount;

/**
 * Employee provident-fund self-service (read-only).
 *
 * GET /api/v1/pf/account?              my PF account + policy + balances
 * GET /api/v1/pf/ledger?from=&to=&type= my contribution/withdrawal ledger
 * GET /api/v1/pf/withdrawals           my withdrawals
 */
class ProvidentFundApiController extends Controller
{
    use ResolvesApiEmployee;

    public function account(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $account = PfAccount::where('employee_id', $employee->employee_id)
            ->with('policy')
            ->first();

        if ($account === null) {
            return ApiResponse::error('No provident fund account found for this employee.', 404);
        }

        return ApiResponse::ok([
            'id' => $account->id,
            'employee_id' => $account->employee_id,
            'status' => $account->status,
            'eligible_from' => $account->eligible_from?->toDateString(),
            'employee_balance' => (float) $account->employee_balance,
            'employer_balance' => (float) $account->employer_balance,
            'total_balance' => round($account->totalBalance(), 2),
            'policy' => $account->policy,
        ], 'Provident fund account.');
    }

    public function ledger(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'entry_type' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $account = PfAccount::where('employee_id', $employee->employee_id)->first();

        if ($account === null) {
            return ApiResponse::error('No provident fund account found for this employee.', 404);
        }

        $query = $account->ledgers()->orderByDesc('entry_date')->orderByDesc('id');

        if (! empty($data['from'])) {
            $query->whereDate('entry_date', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('entry_date', '<=', $data['to']);
        }

        if (! empty($data['entry_type'])) {
            $query->where('entry_type', $data['entry_type']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'Provident fund ledger.');
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $account = PfAccount::where('employee_id', $employee->employee_id)->first();

        if ($account === null) {
            return ApiResponse::error('No provident fund account found for this employee.', 404);
        }

        return ApiResponse::ok(
            $account->withdrawals()->orderByDesc('id')->get(),
            'Provident fund withdrawals.'
        );
    }
}

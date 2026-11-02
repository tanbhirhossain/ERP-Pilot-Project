<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\PAYROLL\Actions\ApprovePayrollLoanAction;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

final class PayrollLoanService
{
    public function __construct(
        private readonly PayrollLoanRepositoryInterface $loans,
        private readonly ApprovePayrollLoanAction $approval,
        private readonly EmployeePayrollDataProviderInterface $employees,
    ) {}

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->loans->paginate($filters, $perPage);
    }

    public function findOrFail(int $id): PayrollLoan
    {
        return $this->loans->findOrFail($id);
    }

    public function request(array $data, ?int $actorId): PayrollLoan
    {
        if ((float) $data['principal_amount'] <= 0 || (float) $data['principal_amount'] > 9999999999.99 || (int) $data['installment_count'] < 1 || (int) $data['installment_count'] > 120) {
            throw ValidationException::withMessages(['principal_amount' => 'Enter a positive loan amount within the supported limit and between 1 and 120 monthly installments.']);
        }
        $data['principal_amount'] = round((float) $data['principal_amount'], 2);
        $employee = $this->employees->employeeForPayroll($data['employee_id'], now()->toDateString(), now()->toDateString());
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'No active HRM salary record was found for this employee.']);
        }
        $data['employee_name'] = $employee->name;
        $data['interest_rate'] = 0.0;
        $data['requested_by'] = $actorId;
        $data['approval_steps'] = PayrollLoanApprovalSteps::normalize(PayrollSetting::query()->first()?->loan_approval_steps);
        $data['installment_amount'] = round((float) $data['principal_amount'] / (int) $data['installment_count'], 2);
        $data['status'] = 'pending';
        unset($data['approval_steps_text']);

        return $this->loans->create($data);
    }

    public function approve(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return $this->approval->approve($loan, $actorId, $comments);
    }

    public function reject(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return $this->approval->reject($loan, $actorId, $comments);
    }

    /** Create missing repayment schedules for one, selected, or all active loans without bypassing loan approval. */
    public function allocateMissingInstallments(array $loanIds = [], bool $all = false): array
    {
        $query = PayrollLoan::query()->where('status', 'active')
            ->whereDoesntHave('installments');
        if (!$all) $query->whereIn('id', $loanIds);
        $allocated = 0; $skipped = 0;
        $query->orderBy('id')->chunkById(100, function ($loans) use (&$allocated, &$skipped): void {
            foreach ($loans as $loan) {
                DB::transaction(function () use ($loan, &$allocated, &$skipped): void {
                    $locked = PayrollLoan::query()->lockForUpdate()->findOrFail($loan->id);
                    if ($locked->status !== 'active' || $locked->installments()->exists()) { $skipped++; return; }
                    $principal = round((float) $locked->principal_amount, 2);
                    $count = max(1, (int) $locked->installment_count);
                    $standard = round($principal / $count, 2); $remaining = $principal;
                    $firstDue = Carbon::parse($locked->first_due_date)->startOfDay();
                    for ($i = 1; $i <= $count; $i++) {
                        $amount = $i === $count ? $remaining : min($remaining, $standard);
                        PayrollLoanInstallment::query()->create([
                            'payroll_loan_id' => $locked->id, 'installment_number' => $i,
                            'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                            'amount' => $amount, 'status' => 'scheduled',
                        ]);
                        $remaining = round($remaining - $amount, 2);
                    }
                    $locked->update(['installment_amount' => $standard, 'interest_rate' => 0]);
                    $allocated++;
                });
            }
        });
        return ['allocated' => $allocated, 'skipped' => $skipped];
    }

    /** Reserve at most one due, zero-interest repayment per loan for a payroll month. */
    public function allocateMonthlyInstallments(int $year, int $month, string $scope, array $employeeIds, ?int $actorId): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || ! in_array($scope, ['single', 'selected', 'all'], true)) {
            throw ValidationException::withMessages(['allocation' => 'Choose a valid payroll period and allocation scope.']);
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('strval', $employeeIds))));
        if ($scope !== 'all' && $employeeIds === []) {
            throw ValidationException::withMessages(['employee_ids' => 'Select at least one employee for this allocation.']);
        }
        if ($scope === 'single' && count($employeeIds) !== 1) {
            throw ValidationException::withMessages(['employee_ids' => 'Single-employee allocation requires exactly one employee.']);
        }

        return DB::transaction(function () use ($year, $month, $scope, $employeeIds, $actorId): array {
            if (PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->exists()) {
                throw ValidationException::withMessages(['period' => 'Monthly loan allocation must be completed before creating the salary sheet for this period. Existing salary sheets use an immutable calculation snapshot.']);
            }

            $now = now();
            DB::table('payroll_loan_monthly_batches')->insertOrIgnore([
                'pay_year' => $year,
                'pay_month' => $month,
                'scope' => $scope === 'all' ? 'all' : 'selected',
                'employee_ids' => $scope === 'all' ? null : json_encode($employeeIds, JSON_THROW_ON_ERROR),
                'status' => 'open',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $batch = PayrollLoanMonthlyBatch::query()->where('pay_year', $year)->where('pay_month', $month)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'open') {
                throw ValidationException::withMessages(['period' => 'This month’s loan allocation is already locked by payroll.']);
            }

            $isAll = $scope === 'all' || $batch->scope === 'all';
            if ($scope === 'all' && $batch->scope !== 'all') {
                $batch->update(['scope' => 'all', 'employee_ids' => null]);
            } elseif (! $isAll) {
                $merged = array_values(array_unique(array_merge($batch->employee_ids ?? [], $employeeIds)));
                $batch->update(['scope' => 'selected', 'employee_ids' => $merged]);
            }

            $loanQuery = PayrollLoan::query()->where('status', 'active');
            if (! $isAll) $loanQuery->whereIn('employee_id', $employeeIds);
            $loanIds = $loanQuery->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if ($loanIds === []) {
                return ['allocated' => 0, 'amount' => 0.0, 'existing' => $batch->allocations()->count(), 'scope' => $batch->scope, 'status' => $batch->status, 'year' => $year, 'month' => $month];
            }

            // Serialize allocations per loan across payroll periods, not just per installment.
            $blockedLoanIds = PayrollLoanMonthlyAllocation::query()
                ->whereIn('payroll_loan_id', $loanIds)->whereIn('status', ['allocated', 'attached'])
                ->lockForUpdate()->pluck('payroll_loan_id')->map(fn ($id) => (int) $id)->all();
            $legacyAttachedLoanIds = PayrollLoanInstallment::query()
                ->whereIn('payroll_loan_id', $loanIds)->where('status', 'attached')
                ->lockForUpdate()->pluck('payroll_loan_id')->map(fn ($id) => (int) $id)->all();
            $blockedLoanIds = array_values(array_unique(array_merge($blockedLoanIds, $legacyAttachedLoanIds)));
            $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            $query = PayrollLoanInstallment::query()
                ->with('loan:id,employee_id,status,interest_rate')
                ->whereIn('payroll_loan_id', $loanIds)
                ->when($blockedLoanIds !== [], fn ($installments) => $installments->whereNotIn('payroll_loan_id', $blockedLoanIds))
                ->where('status', 'scheduled')
                ->whereDate('due_date', '<=', $periodEnd)
                ->whereDoesntHave('monthlyAllocation')
                ->orderBy('payroll_loan_id')->orderBy('due_date')->orderBy('installment_number')->orderBy('id')
                ->lockForUpdate();

            $firstDueByLoan = $query->get()->groupBy('payroll_loan_id')->map(fn ($rows) => $rows->first());
            $allocated = 0;
            $allocatedAmount = 0.0;
            foreach ($firstDueByLoan as $installment) {
                if (! $installment->loan || (float) $installment->loan->interest_rate !== 0.0) {
                    throw ValidationException::withMessages(['loan_installments' => 'A scheduled repayment is inconsistent with the 0% interest loan policy. Contact payroll support before retrying.']);
                }
                PayrollLoanMonthlyAllocation::query()->create([
                    'batch_id' => $batch->id,
                    'payroll_loan_id' => $installment->payroll_loan_id,
                    'payroll_loan_installment_id' => $installment->id,
                    'employee_id' => $installment->loan->employee_id,
                    'amount' => $installment->amount,
                    'status' => 'allocated',
                    'created_by' => $actorId,
                    'allocated_at' => now(),
                ]);
                $allocated++;
                $allocatedAmount = round($allocatedAmount + (float) $installment->amount, 2);
            }

            $batch->refresh();
            return [
                'allocated' => $allocated,
                'amount' => $allocatedAmount,
                'existing' => $batch->allocations()->count(),
                'scope' => $batch->scope,
                'status' => $batch->status,
                'year' => $year,
                'month' => $month,
            ];
        }, 3);
    }
}

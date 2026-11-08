<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\PayrollLoanInstallment;

final class ApplyLoanInstallmentAction
{
    /** @param array<int, string> $employeeIds
     * @param  array<string, array<int, array<string, mixed>>>  $snapshotByEmployee
     * @return array<string, array{amount: float, installments: array<int, array<string, mixed>>}>
     */
    public function lockSnapshotForEmployees(array $employeeIds, array $snapshotByEmployee): array
    {
        $expected = [];
        foreach ($employeeIds as $employeeId) {
            foreach ($snapshotByEmployee['e:'.(string) $employeeId] ?? [] as $installment) {
                $expected[] = ['employee_id' => (string) $employeeId] + $installment + ['monthly_allocation_id' => null];
            }
        }
        if ($expected === []) {
            return [];
        }

        $ids = array_map(fn (array $row): int => (int) $row['installment_id'], $expected);
        $actual = PayrollLoanInstallment::query()->with(['loan:id,employee_id,status,interest_rate', 'monthlyAllocation.batch:id,status'])
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $grouped = [];

        foreach ($expected as $item) {
            $row = $actual->get((int) $item['installment_id']);
            $matchesSnapshot = $row !== null
                && $row->status === 'scheduled'
                && (string) $row->loan?->employee_id === $item['employee_id']
                && in_array($row->loan?->status, ['approved', 'active'], true)
                && (float) $row->loan?->interest_rate === 0.0
                && (int) $row->payroll_loan_id === (int) $item['loan_id']
                && $row->due_date?->toDateString() === $item['due_date']
                && round((float) $row->amount, 2) === round((float) $item['amount'], 2)
                && (($item['monthly_allocation_id'] === null && $row->monthlyAllocation === null)
                    || ($row->monthlyAllocation !== null
                        && (int) $row->monthlyAllocation->id === (int) $item['monthly_allocation_id']
                        && $row->monthlyAllocation->status === 'allocated'
                        && $row->monthlyAllocation->batch?->status === 'locked'));
            if (! $matchesSnapshot) {
                throw ValidationException::withMessages(['loan_installments' => "A loan installment for employee {$item['employee_id']} changed after the salary-sheet snapshot. Reconcile the installment state before retrying."]);
            }
            $grouped[$item['employee_id']][] = [
                'installment_id' => (int) $item['installment_id'],
                'monthly_allocation_id' => $item['monthly_allocation_id'] === null ? null : (int) $item['monthly_allocation_id'],
                'loan_id' => (int) $item['loan_id'],
                'due_date' => (string) $item['due_date'],
                'amount' => (float) $item['amount'],
            ];
        }

        $result = [];
        foreach ($grouped as $employeeId => $installments) {
            $result[$employeeId] = [
                'amount' => round(array_sum(array_column($installments, 'amount')), 2),
                'installments' => $installments,
            ];
        }

        return $result;
    }
}

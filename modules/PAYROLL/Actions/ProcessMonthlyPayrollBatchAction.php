<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Models\PayrollCalculationSnapshot;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollCutlistItem;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollCalculationEngineService;

final class ProcessMonthlyPayrollBatchAction
{
    public function __construct(
        private readonly GenerateSalaryCutlistAction $cutlists,
        private readonly PayrollCalculationEngineService $engine,
        private readonly CapturePayrollCalculationSnapshotAction $snapshots,
        private readonly ApplyLoanInstallmentAction $loanInstallments,
    ) {}

    public function execute(int $sheetId): void
    {
        $sheet = DB::transaction(function () use ($sheetId): ?PayrollSalarySheet {
            $locked = PayrollSalarySheet::query()->lockForUpdate()->findOrFail($sheetId);
            if (in_array($locked->status, ['ready', 'released'], true)) {
                return null;
            }
            $locked->update([
                'status' => 'processing',
                'processed_count' => 0,
                'started_at' => $locked->started_at ?? now(),
                'failure_message' => null,
            ]);

            return $locked->fresh();
        });
        if ($sheet === null) {
            return;
        }

        $hasExistingSlips = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->exists();
        $hasCalculationSnapshot = PayrollCalculationSnapshot::query()->where('salary_sheet_id', $sheet->id)->exists();
        if ($hasExistingSlips && ! $hasCalculationSnapshot) {
            throw new \RuntimeException('This partial salary sheet predates calculation snapshots; do not retry it automatically. Reconcile its existing slips before proceeding.');
        }
        $calculationSnapshot = $this->snapshots->execute($sheet);
        $employees = collect($calculationSnapshot['employees'] ?? [])->map(fn (array $data) => EmployeePayrollData::fromArray($data));
        $eligibleCount = $employees->count();

        if ($sheet->payroll_cutlist_id) {
            $cutlist = PayrollCutlist::query()->findOrFail($sheet->payroll_cutlist_id);
            $cutlistEmployeeIds = PayrollCutlistItem::query()->where('payroll_cutlist_id', $cutlist->id)->orderBy('employee_id')->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
            $snapshotEmployeeIds = $employees->pluck('employeeId')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $cutlistEmployeeIds = collect($cutlistEmployeeIds)->sort()->values()->all();
            if ($cutlistEmployeeIds !== $snapshotEmployeeIds) {
                if ($hasExistingSlips) {
                    throw new \RuntimeException('Partial salary slips and cutlist do not match the immutable employee snapshot; do not recalculate this sheet.');
                }
                $cutlist = $this->cutlists->execute($sheet->pay_year, $sheet->pay_month, $sheet->created_by, true, $employees->all());
            }
        } elseif ($hasExistingSlips) {
            throw new \RuntimeException('Partial payroll slips exist without their source cutlist; do not recalculate this sheet.');
        } else {
            $cutlist = $this->cutlists->execute($sheet->pay_year, $sheet->pay_month, $sheet->created_by, true, $employees->all());
        }
        $sheet->update([
            'employee_count' => $eligibleCount,
            'payroll_cutlist_id' => $cutlist->id,
        ]);

        foreach ($employees->chunk(50) as $employeeChunk) {
            DB::transaction(function () use ($sheet, $employeeChunk, $cutlist, $calculationSnapshot): void {
                $employeeIds = $employeeChunk->pluck('employeeId')->all();
                $existingEmployeeIds = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->whereIn('employee_id', $employeeIds)->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
                $toProcessIds = array_values(array_diff($employeeIds, $existingEmployeeIds));
                $loanInstallments = $this->loanInstallments->lockSnapshotForEmployees($toProcessIds, $calculationSnapshot['loan_installments'] ?? []);
                foreach ($employeeChunk as $employee) {
                    if (in_array($employee->employeeId, $existingEmployeeIds, true)) {
                        continue;
                    }

                    $cutlistItem = PayrollCutlistItem::query()
                        ->where('payroll_cutlist_id', $cutlist->id)
                        ->where('employee_id', $employee->employeeId)
                        ->first();
                    $loanData = $loanInstallments[$employee->employeeId] ?? ['amount' => 0.0, 'installments' => []];
                    $calculation = $this->engine->calculate($employee, $sheet->pay_year, $sheet->pay_month, $cutlistItem, (bool) $sheet->include_punishments, $calculationSnapshot, $loanData);
                    $slip = PayrollSlip::query()->create([
                        'salary_sheet_id' => $sheet->id,
                        'employee_id' => $employee->employeeId,
                        'employee_name' => $employee->name,
                        'department' => $employee->department,
                        'designation' => $employee->designation,
                        'pay_year' => $sheet->pay_year,
                        'pay_month' => $sheet->pay_month,
                        'basic_salary' => $calculation['basic_salary'],
                        'gross_salary' => $calculation['gross_salary'],
                        'total_benefits' => $calculation['total_benefits'],
                        'total_deductions' => $calculation['total_deductions'],
                        'net_salary' => $calculation['net_salary'],
                        'payment_status' => 'unpaid',
                        'breakdown_json' => $calculation['breakdown_json'],
                    ]);

                    $installmentIds = collect($calculation['breakdown_json']['loan_installments'])->pluck('installment_id');
                    if ($installmentIds->isNotEmpty()) {
                        PayrollLoanInstallment::query()
                            ->whereIn('id', $installmentIds)
                            ->where('status', 'scheduled')
                            ->update(['status' => 'attached', 'payroll_slip_id' => $slip->id]);

                        $monthlyAllocationIds = collect($calculation['breakdown_json']['loan_installments'])
                            ->pluck('monthly_allocation_id')->filter()->unique()->values();
                        if ($monthlyAllocationIds->isNotEmpty()) {
                            PayrollLoanMonthlyAllocation::query()
                                ->whereIn('id', $monthlyAllocationIds)
                                ->where('status', 'allocated')
                                ->update(['status' => 'attached', 'payroll_slip_id' => $slip->id, 'attached_at' => now()]);
                        }
                    }
                }
            }, 3);

            $sheet->increment('processed_count', $employeeChunk->count());
        }

        $totals = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)
            ->selectRaw('COALESCE(SUM(gross_salary), 0) as gross_total, COALESCE(SUM(total_deductions), 0) as deduction_total, COALESCE(SUM(net_salary), 0) as net_total')
            ->first();

        $sheet->update([
            'status' => 'ready',
            'gross_total' => $totals->gross_total,
            'deduction_total' => $totals->deduction_total,
            'net_total' => $totals->net_total,
            'employee_count' => $sheet->slips()->count(),
            'processed_count' => $sheet->slips()->count(),
            'finished_at' => now(),
        ]);
    }
}

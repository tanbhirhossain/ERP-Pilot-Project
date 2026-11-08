<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\EmployeeRecurringAdjustment;
use Modules\PAYROLL\Models\SalaryBenefitTemplate;
use Modules\PAYROLL\Models\SalaryDeductionTemplate;

final class PayrollAdjustmentService
{
    public function __construct(private readonly EmployeePayrollDataProviderInterface $employees) {}

    public function catalog(?string $search = null): array
    {
        return [
            'benefits' => SalaryBenefitTemplate::query()->latest('id')->get(),
            'deductions' => SalaryDeductionTemplate::query()->latest('id')->get(),
            'employee_adjustments' => EmployeeRecurringAdjustment::query()->when($search, function ($q) use ($search): void {
                $needle = '%'.addcslashes(trim($search), '%_').'%';
                $q->where(fn ($g) => $g->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle)->orWhere('name', 'like', $needle));
            })->latest('id')->paginate(25)->withQueryString(),
        ];
    }

    public function create(array $data, ?int $actorId): void
    {
        DB::transaction(function () use ($data, $actorId): void {
            if (($data['scope'] ?? 'global') === 'employee') {
                $employee = $this->employees->employeeForPayroll($data['employee_id'], $data['effective_from'], $data['effective_from']);
                if ($employee === null) {
                    throw ValidationException::withMessages(['employee_id' => 'No effective HRM salary record was found for this employee.']);
                }
                EmployeeRecurringAdjustment::query()->create([
                    'employee_id' => $data['employee_id'],
                    'employee_name' => $employee->name,
                    'adjustment_type' => $data['adjustment_type'],
                    'name' => $data['name'],
                    'calculation_type' => $data['calculation_type'],
                    'amount' => $data['amount'],
                    'percentage_base' => $data['percentage_base'] ?? 'basic',
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'is_active' => true,
                    'created_by' => $actorId,
                ]);

                return;
            }

            if (empty($data['code'])) {
                throw ValidationException::withMessages(['code' => 'A unique code is required for a global template.']);
            }

            $common = [
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'calculation_type' => $data['calculation_type'],
                'amount' => $data['amount'],
                'percentage_base' => $data['percentage_base'] ?? 'basic',
                'auto_apply' => (bool) ($data['auto_apply'] ?? false),
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => true,
            ];
            if ($data['adjustment_type'] === 'benefit') {
                SalaryBenefitTemplate::query()->create($common + ['taxable' => (bool) ($data['taxable'] ?? true), 'pf_eligible' => (bool) ($data['pf_eligible'] ?? false)]);
            } else {
                SalaryDeductionTemplate::query()->create($common);
            }
        });
    }
}

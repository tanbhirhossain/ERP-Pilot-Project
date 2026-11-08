<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\ChecklistTemplate;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\EmployeeMovement;
use Modules\PAYROLL\Models\SalaryIncrement;

class MovementService
{
    public function nextNo(): string
    {
        $last = EmployeeMovement::withTrashed()->orderByDesc('id')->value('movement_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;

        return 'MOV-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    public function create(string $employeeId, array $data): EmployeeMovement
    {
        return DB::transaction(function () use ($employeeId, $data) {
            $emp = Employee::where('employee_id', $employeeId)->firstOrFail();
            $fromDept = $emp->department_id;
            $fromDes = $emp->designation_id;
            $curSal = $emp->currentSalary?->gross_salary ?? $emp->salaries()->latest('id')->value('gross_salary') ?? 0;

            return EmployeeMovement::create([
                'movement_no' => $this->nextNo(),
                'employee_id' => $employeeId,
                'movement_type' => $data['movement_type'],
                'effective_date' => $data['effective_date'],
                'from_department_id' => $fromDept,
                'to_department_id' => $data['to_department_id'] ?? $fromDept,
                'from_designation_id' => $fromDes,
                'to_designation_id' => $data['to_designation_id'] ?? $fromDes,
                'from_grade' => $data['from_grade'] ?? null,
                'to_grade' => $data['to_grade'] ?? null,
                'from_salary' => $curSal,
                'to_salary' => $data['to_salary'] ?? $curSal,
                'increment_amount' => max(0, (float) ($data['to_salary'] ?? $curSal) - (float) $curSal),
                'reason' => $data['reason'] ?? null,
                'status' => 'Draft',
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function submit(EmployeeMovement $mov): EmployeeMovement
    {
        if (! $mov->isDraft()) {
            throw new \DomainException('Only Draft can be submitted.');
        }
        $mov->update(['status' => 'Pending']);

        return $mov->fresh();
    }

    public function approve(EmployeeMovement $mov): EmployeeMovement
    {
        return DB::transaction(function () use ($mov) {
            $locked = EmployeeMovement::lockForUpdate()->findOrFail($mov->id);
            if (! $locked->isPending()) {
                throw new \DomainException('Only Pending can be approved.');
            }
            $locked->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            // Apply to employee master
            $emp = Employee::where('employee_id', $locked->employee_id)->firstOrFail();
            $updates = [];
            if ($locked->to_department_id) {
                $updates['department_id'] = $locked->to_department_id;
            }
            if ($locked->to_designation_id) {
                $updates['designation_id'] = $locked->to_designation_id;
            }
            if ($updates) {
                $emp->update($updates);
            }
            // Salary history if changed
            if ((float) $locked->to_salary !== (float) $locked->from_salary && (float) $locked->to_salary > 0) {
                $latest = EmployeeSalary::where('employee_id', $locked->employee_id)->where('is_current', true)->first();
                if ($latest) {
                    $latest->update(['is_current' => false, 'effective_to' => now()->subDay()->toDateString()]);
                }
                $inc = (float) $locked->increment_amount;
                $newBasic = $latest ? (float) $latest->basic_salary + $inc * 0.6 : (float) $locked->to_salary * 0.6;
                EmployeeSalary::create([
                    'employee_id' => $locked->employee_id,
                    'basic_salary' => round($newBasic, 2),
                    'gross_salary' => (float) $locked->to_salary,
                    'house_rent' => round((float) $locked->to_salary * 0.3, 2),
                    'medical_allowance' => round((float) $locked->to_salary * 0.05, 2),
                    'other_allowance' => round((float) $locked->to_salary - $newBasic - (float) $locked->to_salary * 0.35, 2),
                    'net_salary' => (float) $locked->to_salary,
                    'effective_from' => $locked->effective_date,
                    'is_current' => true,
                    'remarks' => "Movement {$locked->movement_no} ({$locked->movement_type})",
                ]);
                // Optional: push PAYROLL increment (if PAYROLL module present, create salary_increments row for audit)
                try {
                    if (class_exists(SalaryIncrement::class)) {
                        SalaryIncrement::create([
                            'employee_id' => $locked->employee_id,
                            'increment_type' => 'fixed',
                            'increment_value' => round($inc, 2),
                            'previous_basic' => (float) ($latest?->basic_salary ?? 0),
                            'new_basic' => round($newBasic, 2),
                            'previous_gross' => (float) $locked->from_salary,
                            'new_gross' => (float) $locked->to_salary,
                            'effective_date' => $locked->effective_date,
                            'status' => 'approved',
                            'requested_by' => auth()->id(),
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                            'reason' => "HRM Movement {$locked->movement_no}",
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Not rethrown: the movement itself is already approved and rolling that back
                    // because the payroll mirror could not be written would strand the HR change.
                    // But payroll would then never learn about the salary change, so log it.
                    Log::warning('Approved movement could not be mirrored into payroll', [
                        'movement_id' => $locked->id,
                        'movement_no' => $locked->movement_no,
                        'employee_id' => $locked->employee_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            // Auto-assign onboarding checklist for transfer (department change) if template exists
            try {
                if (class_exists(ChecklistTemplate::class) && $locked->movement_type === 'Transfer') {
                    $tpl = ChecklistTemplate::where('category', 'Onboarding')->where('is_active', true)->first();
                    if ($tpl) {
                        app(ChecklistService::class)->assign($locked->employee_id, $tpl->id, $locked->id);
                    }
                }
            } catch (\Throwable $e) {
                // Deliberately logged rather than rethrown: auto-assigning the onboarding checklist
                // is a convenience step, and failing an already-approved transfer over it would be
                // worse. It must not be silent, though — HR would otherwise never learn that the
                // checklist for this transfer was never created.
                Log::warning('Onboarding checklist auto-assign failed for movement', [
                    'movement_id' => $locked->id,
                    'employee_id' => $locked->employee_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $locked->fresh(['employee', 'toDepartment', 'toDesignation']);
        });
    }

    public function reject(EmployeeMovement $mov, string $reason): EmployeeMovement
    {
        if (! $mov->isPending()) {
            throw new \DomainException('Only Pending can be rejected.');
        }
        $mov->update(['status' => 'Rejected', 'reason' => ($mov->reason ? $mov->reason."\nReject: ".$reason : $reason)]);

        return $mov->fresh();
    }

    public function cancel(EmployeeMovement $mov): EmployeeMovement
    {
        if (! in_array($mov->status, ['Draft', 'Pending'], true)) {
            throw new \DomainException('Only Draft/Pending can be cancelled.');
        }
        $mov->update(['status' => 'Cancelled']);

        return $mov->fresh();
    }
}

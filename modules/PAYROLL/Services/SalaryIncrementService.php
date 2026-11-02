<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Actions\ApproveSalaryIncrementAction;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\SalaryIncrement;

final class SalaryIncrementService
{
    private const MAX_SALARY_AMOUNT = 9999999999.99;

    public function __construct(
        private readonly EmployeePayrollDataProviderInterface $employees,
        private readonly ApproveSalaryIncrementAction $approval,
    ) {}

    public function paginate(int $perPage = 25, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $needle = $search !== null && trim($search) !== ''
            ? '%'.addcslashes(trim($search), '%_').'%'
            : null;

        return SalaryIncrement::query()
            ->with(['requester:id,name', 'approver:id,name', 'rejector:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($needle, fn ($query) => $query->where(function ($group) use ($needle): void {
                $group->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle);
            }))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $perPage)))
            ->withQueryString();
    }

    public function create(array $data, ?int $requesterId): SalaryIncrement
    {
        $result = $this->createForEmployees($data, [(string) $data['employee_id']], $requesterId);
        if ($result['created']->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_id' => $result['duplicates'] > 0
                    ? 'A pending or applied increment already exists for this employee and effective date.'
                    : 'The employee has no effective payroll salary for this date.',
            ]);
        }
        return $result['created']->first();
    }

    /**
     * Create pending, auditable requests for one or more employees. Employee-row locks
     * serialize duplicate submissions for the same effective date.
     *
     * @return array{created: Collection<int, SalaryIncrement>, skipped: int, duplicates: int, issues: array<int, string>}
     */
    public function createForEmployees(array $data, array $employeeIds, ?int $requesterId): array
    {
        $this->validateChange($data);

        return DB::transaction(function () use ($data, $employeeIds, $requesterId): array {
            $created = collect();
            $skipped = 0;
            $duplicates = 0;
            $issues = [];

            $uniqueEmployeeIds = array_values(array_unique(array_map('strval', $employeeIds)));
            sort($uniqueEmployeeIds, SORT_STRING);
            foreach ($uniqueEmployeeIds as $employeeId) {
                $employeeRow = Employee::query()->where('employee_id', $employeeId)->lockForUpdate()->first();
                if ($employeeRow === null) {
                    $skipped++;
                    $issues[] = $employeeId.': employee was not found.';
                    continue;
                }

                $duplicate = SalaryIncrement::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('effective_date', $data['effective_date'])
                    ->whereIn('status', ['pending', 'approved', 'applied'])
                    ->exists();
                if ($duplicate) {
                    $duplicates++;
                    continue;
                }

                try {
                    $employee = $this->employees->employeeForPayroll($employeeId, $data['effective_date'], $data['effective_date']);
                } catch (ValidationException $exception) {
                    $skipped++;
                    $issues[] = $employeeId.': '.collect($exception->errors())->flatten()->first();
                    continue;
                }
                if ($employee === null) {
                    $skipped++;
                    $issues[] = $employeeId.': no eligible HRM salary record was found for the effective date.';
                    continue;
                }

                $created->push($this->createForEmployee($data, $employee, $requesterId));
            }

            return compact('created', 'skipped', 'duplicates', 'issues');
        }, 3);
    }

    public function approve(SalaryIncrement $increment, int $approverId, ?string $comments = null): SalaryIncrement
    {
        return $this->approval->execute($increment, $approverId, $comments);
    }

    public function reject(SalaryIncrement $increment, int $approverId, ?string $comments = null): SalaryIncrement
    {
        return DB::transaction(function () use ($increment, $approverId, $comments): SalaryIncrement {
            $locked = SalaryIncrement::query()->lockForUpdate()->findOrFail($increment->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['increment' => 'Only pending salary increments can be rejected.']);
            }
            $locked->update([
                'status' => 'rejected',
                'rejected_by' => $approverId,
                'rejected_at' => now(),
                'decision_comments' => $comments,
            ]);
            return $locked->refresh();
        }, 3);
    }

    private function createForEmployee(array $data, EmployeePayrollData $employee, ?int $requesterId): SalaryIncrement
    {
        $value = round((float) $data['increment_value'], 2);
        $factor = $data['increment_type'] === 'percentage' ? 1 + ($value / 100) : null;
        $newBasic = $factor === null ? $employee->basicSalary + $value : $employee->basicSalary * $factor;
        $newGross = $factor === null ? $employee->grossSalary + $value : $employee->grossSalary * $factor;

        if ($newBasic <= 0 || $newGross <= 0 || $newBasic > self::MAX_SALARY_AMOUNT || $newGross > self::MAX_SALARY_AMOUNT) {
            throw ValidationException::withMessages(['increment_value' => 'The resulting basic or gross salary is outside the supported payroll range.']);
        }

        return SalaryIncrement::query()->create([
            'employee_id' => $employee->employeeId,
            'employee_name' => $employee->name,
            'increment_type' => $data['increment_type'],
            'increment_value' => $value,
            'previous_basic' => round($employee->basicSalary, 2),
            'new_basic' => round($newBasic, 2),
            'previous_gross' => round($employee->grossSalary, 2),
            'new_gross' => round($newGross, 2),
            'effective_date' => $data['effective_date'],
            'status' => 'pending',
            'requested_by' => $requesterId,
            'reason' => trim((string) $data['reason']),
        ]);
    }

    private function validateChange(array $data): void
    {
        $value = (float) ($data['increment_value'] ?? 0);
        if (!in_array($data['increment_type'] ?? null, ['fixed', 'percentage'], true) || $value <= 0 || $value > 9999999999.99) {
            throw ValidationException::withMessages(['increment_value' => 'Enter a positive supported increase amount.']);
        }
        if (($data['increment_type'] ?? null) === 'percentage' && $value > 1000) {
            throw ValidationException::withMessages(['increment_value' => 'Percentage increases cannot exceed 1,000%.']);
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for an auditable salary change.']);
        }
    }
}

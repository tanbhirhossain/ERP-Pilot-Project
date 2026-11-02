<?php

namespace Modules\PAYROLL\Services\HRM;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Services\EmployeeService;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;

final class HrmEmployeePayrollDataProvider implements EmployeePayrollDataProviderInterface
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function employeesForPayroll(string $periodStart, string $periodEnd, array $excludedEmployeeIds = []): LazyCollection
    {
        $excludedEmployeeIds = array_values(array_unique(array_map('strval', $excludedEmployeeIds)));

        return Employee::query()
            ->when($excludedEmployeeIds !== [], fn ($query) => $query->whereNotIn('employees.employee_id', $excludedEmployeeIds))
            ->where('is_active', true)
            ->whereIn('status', ['Active', 'On Leave'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_resignations as er')->whereColumn('er.employee_id', 'employees.employee_id')->where('er.status', 'Approved')->whereDate('er.last_working_date', '<=', $periodEnd))
            ->with(['department:id,name', 'designation:id,name', 'salaries'])
            ->orderBy('id')
            ->lazyById(50)
            ->map(fn (Employee $employee): ?EmployeePayrollData => $this->mapEmployee($employee, $periodStart, $periodEnd))
            ->filter()
            ->values();
    }

    public function employeeOptions(?string $search = null, int $limit = 200): Collection
    {
        return $this->employees->options($search, $limit);
    }

    public function countEmployeesForPayroll(string $periodStart, string $periodEnd): int
    {
        return Employee::query()
            ->where('is_active', true)
            ->whereIn('status', ['Active', 'On Leave'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_resignations as er')->whereColumn('er.employee_id', 'employees.employee_id')->where('er.status', 'Approved')->whereDate('er.last_working_date', '<=', $periodEnd))
            ->whereHas('salaries', fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart)))
            ->count();
    }

    public function employeeForPayroll(string $employeeId, string $periodStart, string $periodEnd): ?EmployeePayrollData
    {
        $employee = $this->employees->findByEmployeeId($employeeId);
        if ($employee === null || ! $employee->is_active || ! in_array($employee->status, ['Active', 'On Leave'], true) || \Modules\HRM\Models\Employee\EmployeeResignation::query()->where('employee_id',$employeeId)->where('status','Approved')->whereDate('last_working_date','<=',$periodEnd)->exists()) {
            return null;
        }

        $employee->load(['department:id,name', 'designation:id,name', 'salaries']);

        return $this->mapEmployee($employee, $periodStart, $periodEnd);
    }

    public function employeeForSettlement(string $employeeId, string $periodStart, string $periodEnd): ?EmployeePayrollData
    {
        $employee = Employee::query()->withTrashed()->where('employee_id', $employeeId)->first();
        if ($employee === null) {
            return null;
        }

        $employee->load(['department:id,name', 'designation:id,name', 'salaries']);

        return $this->mapEmployee($employee, $periodStart, $periodEnd);
    }

    public function applySalaryRevision(string $employeeId, float $newBasic, float $newGross, string $effectiveDate, float $expectedBasic, float $expectedGross, int $incrementId): void
    {
        DB::transaction(function () use ($employeeId, $newBasic, $newGross, $effectiveDate, $expectedBasic, $expectedGross, $incrementId): void {
            if ($this->employees->findByEmployeeId($employeeId) === null) {
                throw ValidationException::withMessages(['employee_id' => "HRM employee {$employeeId} was not found."]);
            }

            $date = Carbon::parse($effectiveDate)->toDateString();
            $salaryRows = EmployeeSalary::query()
                ->where('employee_id', $employeeId)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $salaryAtDate = $salaryRows
                ->filter(fn (EmployeeSalary $salary): bool =>
                    ($salary->effective_from === null || $salary->effective_from->toDateString() <= $date)
                    && ($salary->effective_to === null || $salary->effective_to->toDateString() >= $date)
                )
                ->sortByDesc(fn (EmployeeSalary $salary): string => $salary->effective_from?->toDateString() ?? '0000-01-01')
                ->first();

            if ($salaryAtDate === null) {
                throw ValidationException::withMessages(['effective_date' => "No HRM salary-history record covers {$employeeId} on {$date}."]);
            }
            if (abs(round((float) $salaryAtDate->basic_salary, 2) - round($expectedBasic, 2)) > 0.01
                || abs(round((float) $salaryAtDate->gross_salary, 2) - round($expectedGross, 2)) > 0.01) {
                throw ValidationException::withMessages(['increment' => 'The employee salary history changed after this request was created. Reject it and submit a new request using the current salary.']);
            }

            $nextStart = $salaryRows
                ->filter(fn (EmployeeSalary $salary): bool => $salary->effective_from !== null && $salary->effective_from->toDateString() > $date)
                ->sortBy(fn (EmployeeSalary $salary): string => $salary->effective_from->toDateString())
                ->first()?->effective_from?->toDateString();
            $sameDay = $salaryAtDate->effective_from?->toDateString() === $date;
            if ($sameDay) {
                $sameDayEnd = $salaryAtDate->effective_to?->toDateString();
                if ($nextStart !== null) {
                    $beforeNext = Carbon::parse($nextStart)->subDay()->toDateString();
                    if ($sameDayEnd === null || $beforeNext < $sameDayEnd) $sameDayEnd = $beforeNext;
                }
                $salaryAtDate->update([
                    'basic_salary' => round($newBasic, 2),
                    'gross_salary' => round($newGross, 2),
                    'net_salary' => round($newGross, 2),
                    'effective_to' => $sameDayEnd,
                    'remarks' => 'Updated by approved Payroll salary increment #'.$incrementId.'.',
                ]);
                return;
            }

            $newEffectiveTo = $salaryAtDate->effective_to?->toDateString();
            if ($nextStart !== null) {
                $beforeNext = Carbon::parse($nextStart)->subDay()->toDateString();
                if ($newEffectiveTo === null || $beforeNext < $newEffectiveTo) $newEffectiveTo = $beforeNext;
            }

            $wasCurrent = (bool) $salaryAtDate->is_current;
            $salaryAtDate->update([
                'is_current' => false,
                'effective_to' => Carbon::parse($date)->subDay()->toDateString(),
            ]);

            EmployeeSalary::query()->create([
                'employee_id' => $employeeId,
                'basic_salary' => round($newBasic, 2),
                'gross_salary' => round($newGross, 2),
                'house_rent' => $salaryAtDate->house_rent,
                'medical_allowance' => $salaryAtDate->medical_allowance,
                'other_allowance' => $salaryAtDate->other_allowance,
                'net_salary' => round($newGross, 2),
                'pay_grade' => $salaryAtDate->pay_grade,
                'effective_from' => $date,
                'effective_to' => $newEffectiveTo,
                'is_current' => $wasCurrent,
                'remarks' => 'Created by approved Payroll salary increment #'.$incrementId.'.',
            ]);
        }, 3);
    }

    private function mapEmployee(Employee $employee, string $periodStart, string $periodEnd): ?EmployeePayrollData
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->startOfDay();
        $daysInPeriod = $start->daysInMonth;
        $employmentStart = $employee->joining_date ? Carbon::parse($employee->joining_date)->startOfDay()->max($start) : $start->copy();
        if ($employmentStart->gt($end)) return null;

        $salaryRows = $employee->salaries
            ->filter(fn (EmployeeSalary $row): bool =>
                ($row->effective_from === null || $row->effective_from->toDateString() <= $end->toDateString())
                && ($row->effective_to === null || $row->effective_to->toDateString() >= $employmentStart->toDateString())
            )
            ->sortByDesc(fn (EmployeeSalary $row): string => $row->effective_from?->toDateString() ?? '0000-01-01')
            ->values();

        $segments = [];
        $weighted = ['basic_salary' => 0.0, 'gross_salary' => 0.0, 'house_rent' => 0.0, 'medical_allowance' => 0.0, 'other_allowance' => 0.0];
        $payableDays = 0;
        for ($day = $employmentStart->copy(); $day->lte($end); $day->addDay()) {
            $salary = $salaryRows->first(fn (EmployeeSalary $row): bool =>
                ($row->effective_from === null || $row->effective_from->toDateString() <= $day->toDateString())
                && ($row->effective_to === null || $row->effective_to->toDateString() >= $day->toDateString())
            );
            if ($salary === null) {
                throw ValidationException::withMessages(['salary' => "No HRM salary-history record covers {$employee->employee_id} on {$day->toDateString()}; payroll was stopped rather than silently underpaying the employee."]);
            }

            $rates = [
                'basic_salary' => (float) $salary->basic_salary,
                'gross_salary' => (float) $salary->gross_salary,
                'house_rent' => (float) $salary->house_rent,
                'medical_allowance' => (float) $salary->medical_allowance,
                'other_allowance' => (float) $salary->other_allowance,
            ];
            foreach ($rates as $key => $rate) $weighted[$key] += $rate;
            $payableDays++;

            $lastIndex = count($segments) - 1;
            if ($lastIndex >= 0 && $segments[$lastIndex]['salary_id'] === $salary->id) {
                $segments[$lastIndex]['to'] = $day->toDateString();
                $segments[$lastIndex]['days']++;
            } else {
                $segments[] = [
                    'salary_id' => $salary->id,
                    'from' => $day->toDateString(),
                    'to' => $day->toDateString(),
                    'days' => 1,
                    ...$rates,
                ];
            }
        }
        if ($payableDays === 0) return null;
        $periodAmounts = [
            'basic' => round($weighted['basic_salary'] / $daysInPeriod, 2),
            'gross' => round($weighted['gross_salary'] / $daysInPeriod, 2),
            'house_rent' => round($weighted['house_rent'] / $daysInPeriod, 2),
            'medical' => round($weighted['medical_allowance'] / $daysInPeriod, 2),
            'other_allowance' => round($weighted['other_allowance'] / $daysInPeriod, 2),
        ];
        foreach ($weighted as $key => $amount) $weighted[$key] = round($amount / $payableDays, 2);

        return new EmployeePayrollData(
            employeeId: $employee->employee_id,
            name: $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name),
            department: $employee->department?->name,
            designation: $employee->designation?->name,
            joiningDate: $employee->joining_date?->toDateString(),
            basicSalary: $weighted['basic_salary'],
            grossSalary: $weighted['gross_salary'],
            houseRent: $weighted['house_rent'],
            medicalAllowance: $weighted['medical_allowance'],
            otherAllowance: $weighted['other_allowance'],
            payGrade: $salaryRows->first()?->pay_grade,
            prorationFactor: round($payableDays / $daysInPeriod, 8),
            payableDays: $payableDays,
            daysInPeriod: $daysInPeriod,
            salarySegments: $segments,
            periodAmounts: $periodAmounts,
        );
    }
}

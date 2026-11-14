<?php

namespace Modules\PAYROLL\Services\HRM;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeResignation;
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
            ->where($this->payrollable($periodStart, $periodEnd))
            // `mapEmployee()` returns null for anyone who joins after the period ends; stating the same
            // rule in SQL keeps the count and the batch equal by construction, not by coincidence.
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $periodEnd))
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
            ->where($this->payrollable($periodStart, $periodEnd))
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $periodEnd))
            ->count();
    }

    /**
     * Who payroll can actually build a slip for, and the single place that rule lives.
     *
     * The two selection methods used to disagree: `countEmployeesForPayroll()` asked for a salary row
     * overlapping the period, while `employeesForPayroll()` — the batch the queued sheet walks — had no
     * such filter. Every active employee without a rate was therefore counted as *not mine to worry about*
     * and then walked by the batch, where `mapEmployee()` threw and the whole sheet went to `failed`
     * ("No HRM salary-history record covers M302012 on 2026-10-01; payroll was stopped rather than
     * silently underpaying the employee" — 14 such employees in a 998-person import, so the month could
     * never be run at all). One scope for both keeps the promised count, the cutlist and the slip count
     * agreeing, which `PayrollSalarySheetService::release()` also depends on.
     *
     * Payrollable = a salary row overlaps the period, OR rate continuation is allowed for that employee
     * (they have at least one salary record anywhere and no punishable HRM policy) — which is exactly the
     * condition `mapEmployee()` uses to decide between "pay at the nearest known rate" and "stop".
     */
    private function payrollable(string $periodStart, string $periodEnd): Closure
    {
        return fn ($query) => $query->where(function ($q) use ($periodStart, $periodEnd): void {
            $q->whereHas('salaries', fn ($s) => $s
                ->where(fn ($o) => $o->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd))
                ->where(fn ($o) => $o->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart)))
                ->orWhere(fn ($o) => $o->whereHas('salaries')
                    ->whereDoesntHave('policy', fn ($p) => $p->where('is_punishable', true)));
        });
    }

    /**
     * The complement of {@see self::payrollable()} for a period: active employees payroll had to leave
     * out, with the reason an officer can act on. Surfaced on the salary-sheet screen, because a batch
     * that quietly pays 983 of 997 people is worse than one that says who is missing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unpayableForPeriod(string $periodStart, string $periodEnd, int $limit = 200): Collection
    {
        return Employee::query()
            ->where('is_active', true)
            ->whereIn('status', ['Active', 'On Leave'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_resignations as er')->whereColumn('er.employee_id', 'employees.employee_id')->where('er.status', 'Approved')->whereDate('er.last_working_date', '<=', $periodEnd))
            ->whereNot($this->payrollable($periodStart, $periodEnd))
            ->with(['department:id,name', 'salaries' => fn ($q) => $q->select('employee_id', 'basic_salary', 'gross_salary', 'effective_from', 'effective_to')->orderBy('effective_from')])
            ->orderBy('employee_id')
            ->limit($limit)
            ->get()
            ->map(fn (Employee $employee): array => [
                'employee_id' => (string) $employee->employee_id,
                'employee_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'joining_date' => $employee->joining_date?->toDateString(),
                'reason' => $this->unpayableReason($employee),
            ])
            ->values();
    }

    /** One line an officer can act on: what is missing, and where to put it. */
    private function unpayableReason(Employee $employee): string
    {
        $rows = $employee->salaries;

        if ($rows->isEmpty()) {
            return 'No HRM salary record at all — add one under HRM → Employees → Salary history, then run the sheet again.';
        }

        $from = $rows->map(fn (EmployeeSalary $row): ?string => $row->effective_from?->toDateString())->filter()->sort()->first();
        $to = $rows->map(fn (EmployeeSalary $row): ?string => $row->effective_to?->toDateString())->filter()->sort()->reverse()->first();

        return 'Salary history does not reach this period ('.($from ?? 'undated').' → '.($to ?? 'open').'). Enter a row covering the period.';
    }

    public function employeeForPayroll(string $employeeId, string $periodStart, string $periodEnd): ?EmployeePayrollData
    {
        $employee = $this->employees->findByEmployeeId($employeeId);
        if ($employee === null || ! $employee->is_active || ! in_array($employee->status, ['Active', 'On Leave'], true) || EmployeeResignation::query()->where('employee_id', $employeeId)->where('status', 'Approved')->whereDate('last_working_date', '<=', $periodEnd)->exists()) {
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
                ->filter(fn (EmployeeSalary $salary): bool => ($salary->effective_from === null || $salary->effective_from->toDateString() <= $date)
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
                    if ($sameDayEnd === null || $beforeNext < $sameDayEnd) {
                        $sameDayEnd = $beforeNext;
                    }
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
                if ($newEffectiveTo === null || $beforeNext < $newEffectiveTo) {
                    $newEffectiveTo = $beforeNext;
                }
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
        if ($employmentStart->gt($end)) {
            return null;
        }

        $salaryRows = $employee->salaries
            ->filter(fn (EmployeeSalary $row): bool => ($row->effective_from === null || $row->effective_from->toDateString() <= $end->toDateString())
                && ($row->effective_to === null || $row->effective_to->toDateString() >= $employmentStart->toDateString())
            )
            ->sortByDesc(fn (EmployeeSalary $row): string => $row->effective_from?->toDateString() ?? '0000-01-01')
            ->values();
        // Rate-continuation pool for the non-punishable fallback below: *every* salary record the employee
        // has, including rows that expired before the period — an unentered increment must not stop pay.
        $allSalaryRows = $employee->salaries
            ->sortByDesc(fn (EmployeeSalary $row): string => $row->effective_from?->toDateString() ?? '0000-01-01')
            ->values();

        // An employee whose HRM attendance policy is NOT punishable never stops payroll: HRM owns that
        // employee's pay and discipline, so a missing salary-history row for some day is paid at the
        // nearest known rate and flagged for the payroll officer to backfill. Punishable policies keep
        // the hard stop — the throw is the enforcement mechanism and silently guessing a rate would
        // under- or over-pay an employee whose records must be exact. No salary rows at all is always
        // fatal: there is no rate to fall back to.
        $punishablePolicy = (bool) ($employee->policy?->is_punishable ?? false);
        $rateFallbackAllowed = ! $punishablePolicy && $allSalaryRows->isNotEmpty();
        $fallbackDays = 0;
        $segments = [];
        $weighted = ['basic_salary' => 0.0, 'gross_salary' => 0.0, 'house_rent' => 0.0, 'medical_allowance' => 0.0, 'other_allowance' => 0.0];
        $payableDays = 0;
        for ($day = $employmentStart->copy(); $day->lte($end); $day->addDay()) {
            $salary = $salaryRows->first(fn (EmployeeSalary $row): bool => ($row->effective_from === null || $row->effective_from->toDateString() <= $day->toDateString())
                && ($row->effective_to === null || $row->effective_to->toDateString() >= $day->toDateString())
            );
            $rateFromOtherPeriod = false;
            if ($salary === null) {
                if (! $rateFallbackAllowed) {
                    $coverage = $allSalaryRows->isEmpty()
                        ? 'this employee has no salary record at all'
                        : 'their records run '.collect($allSalaryRows->pluck('effective_from')->filter()->map(fn ($d) => $d->toDateString())->sort())->first().' → '.collect($allSalaryRows->pluck('effective_to')->filter()->map(fn ($d) => $d->toDateString())->sort()->reverse())->first();
                    $why = $punishablePolicy
                        ? 'their HRM attendance policy is punishable, so a guessed rate is not allowed'
                        : $coverage;
                    throw ValidationException::withMessages(['salary' => "No HRM salary-history record covers {$employee->employee_id} on {$day->toDateString()}; payroll was stopped rather than silently underpaying the employee ({$why}). Fix it under HRM → Employees → {$employee->employee_id} → Salary history, then run the sheet again."]);
                }
                // sortByDesc(effective_from) above, so the first row already started is the latest known
                // rate before the gap; if none started, the last row is the earliest one after it.
                $salary = $allSalaryRows->first(fn (EmployeeSalary $row): bool => $row->effective_from !== null && $row->effective_from->toDateString() <= $day->toDateString())
                    ?? $allSalaryRows->last();
                $rateFromOtherPeriod = true;
                $fallbackDays++;
            }

            $rates = [
                'basic_salary' => (float) $salary->basic_salary,
                'gross_salary' => (float) $salary->gross_salary,
                'house_rent' => (float) $salary->house_rent,
                'medical_allowance' => (float) $salary->medical_allowance,
                'other_allowance' => (float) $salary->other_allowance,
            ];
            foreach ($rates as $key => $rate) {
                $weighted[$key] += $rate;
            }
            $payableDays++;

            $lastIndex = count($segments) - 1;
            if ($lastIndex >= 0 && $segments[$lastIndex]['salary_id'] === $salary->id && $segments[$lastIndex]['rate_from_other_period'] === $rateFromOtherPeriod) {
                $segments[$lastIndex]['to'] = $day->toDateString();
                $segments[$lastIndex]['days']++;
            } else {
                $segments[] = [
                    'salary_id' => $salary->id,
                    'from' => $day->toDateString(),
                    'to' => $day->toDateString(),
                    'days' => 1,
                    'rate_from_other_period' => $rateFromOtherPeriod,
                    ...$rates,
                ];
            }
        }
        if ($payableDays === 0) {
            return null;
        }
        $periodAmounts = [
            'basic' => round($weighted['basic_salary'] / $daysInPeriod, 2),
            'gross' => round($weighted['gross_salary'] / $daysInPeriod, 2),
            'house_rent' => round($weighted['house_rent'] / $daysInPeriod, 2),
            'medical' => round($weighted['medical_allowance'] / $daysInPeriod, 2),
            'other_allowance' => round($weighted['other_allowance'] / $daysInPeriod, 2),
        ];
        foreach ($weighted as $key => $amount) {
            $weighted[$key] = round($amount / $payableDays, 2);
        }

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
            notes: $fallbackDays > 0 ? ['salary_rate_fallback_days' => $fallbackDays] : [],
        );
    }
}

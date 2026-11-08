<?php

namespace Modules\PAYROLL\Interfaces\HRM;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\PAYROLL\DTO\EmployeePayrollData;

interface EmployeePayrollDataProviderInterface
{
    /** @return LazyCollection<int, EmployeePayrollData> */
    public function employeesForPayroll(string $periodStart, string $periodEnd, array $excludedEmployeeIds = []): LazyCollection;

    public function employeeOptions(?string $search = null, int $limit = 200): Collection;

    public function countEmployeesForPayroll(string $periodStart, string $periodEnd): int;

    public function employeeForPayroll(string $employeeId, string $periodStart, string $periodEnd): ?EmployeePayrollData;

    public function employeeForSettlement(string $employeeId, string $periodStart, string $periodEnd): ?EmployeePayrollData;

    /** Apply an approved revision through HRM's salary history service boundary. */
    public function applySalaryRevision(string $employeeId, float $newBasic, float $newGross, string $effectiveDate, float $expectedBasic, float $expectedGross, int $incrementId): void;
}

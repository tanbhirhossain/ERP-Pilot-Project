<?php

namespace Modules\PAYROLL\Interfaces\HRM;

use Modules\PAYROLL\DTO\AttendancePayrollSummary;

interface AttendancePayrollDataProviderInterface
{
    public function summaryForEmployee(string $employeeId, string $periodStart, string $periodEnd): AttendancePayrollSummary;

    /** @param array<int, string> $employeeIds
     * @return array<string, AttendancePayrollSummary>
     */
    public function summariesForEmployees(array $employeeIds, string $periodStart, string $periodEnd): array;
}

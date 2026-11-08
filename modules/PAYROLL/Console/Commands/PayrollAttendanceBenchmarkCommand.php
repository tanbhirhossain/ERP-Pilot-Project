<?php

namespace Modules\PAYROLL\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;

final class PayrollAttendanceBenchmarkCommand extends Command
{
    protected $signature = 'payroll:benchmark-attendance {--year= : Payroll year} {--month= : Payroll month} {--employees=750 : Maximum employees to sample}';

    protected $description = 'Benchmark batched PAYROLL attendance summaries against real HRM data without creating payroll slips.';

    public function handle(EmployeePayrollDataProviderInterface $employees, AttendancePayrollDataProviderInterface $attendance): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);
        $limit = (int) $this->option('employees');
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $limit < 1 || $limit > 10000) {
            $this->error('Use year 2000–2100, month 1–12, and employee sample size 1–10000.');

            return self::INVALID;
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $employeeIds = [];
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $startedAt = microtime(true);
        foreach ($employees->employeesForPayroll($start, $end) as $employee) {
            $employeeIds[] = $employee->employeeId;
            if (count($employeeIds) >= $limit) {
                break;
            }
        }
        $processed = 0;
        foreach (array_chunk($employeeIds, 50) as $chunk) {
            $summaries = $attendance->summariesForEmployees($chunk, $start, $end);
            $processed += count($summaries);
        }
        $elapsed = microtime(true) - $startedAt;
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $this->info(sprintf('Attendance benchmark %04d-%02d: %d/%d employees, %.2f seconds, %d SQL queries, %.1f MB peak memory.', $year, $month, $processed, $limit, $elapsed, $queries, $peakMemoryMb));
        if ($processed < $limit) {
            $this->warn('Fewer eligible employees were available than the requested sample size.');
        }
        $this->line('Use the output as a baseline; compare it with an appropriately sized production-like dataset before increasing batch size.');
        $this->comment('The HRM leave-balance adapter may create default leave-type settings if they do not yet exist.');

        return self::SUCCESS;
    }
}

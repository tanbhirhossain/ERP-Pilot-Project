<?php

namespace Modules\CORE\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Services\LeaveBalanceService;

/**
 * Leave-balance accrual (Phase 2 — F21).
 *
 * Materialises each active employee's computed entitlement for the current year
 * into hrm_leave_balances, so reporting and year-end closing have a stable
 * snapshot. Idempotent: firstOrCreate keyed on (employee_id, leave_type_id, year)
 * never overwrites an existing row, so re-running is safe. The live balance used
 * by leave requests is still computed by LeaveBalanceService — this only records
 * the accrual.
 */
class LeaveBalanceAccrualCommand extends Command
{
    protected $signature = 'erp:leave-balance-accrual {--year= : Year to accrue (defaults to the current year)}';

    protected $description = 'Materialise the current-year leave entitlement for active employees (idempotent)';

    public function handle(LeaveBalanceService $balances): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $types = LeaveType::where('is_active', true)->where('days_per_year', '>', 0)->get();
        if ($types->isEmpty()) {
            $this->info('No active leave types with an annual entitlement.');

            return self::SUCCESS;
        }

        $created = 0;
        $employees = Employee::where('status', 'Active')->get(['employee_id']);

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $existing = HrmLeaveBalance::where('employee_id', $employee->employee_id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $computed = $balances->balance($employee->employee_id, $type->id, $year);

                HrmLeaveBalance::create([
                    'employee_id' => $employee->employee_id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                    'opening_balance' => 0,
                    'entitlement' => $computed['entitlement'],
                    'carry_forward' => 0,
                    'availed' => $computed['approved'],
                    'pending' => $computed['pending'],
                    'encashed' => 0,
                    'lapsed' => 0,
                    'available' => $computed['available'],
                    'is_closed' => false,
                ]);
                $created++;
            }
        }

        Log::info("erp:leave-balance-accrual: materialised {$created} balance row(s) for {$year}.");
        $this->info("Materialised {$created} leave-balance row(s) for {$year}.");

        return self::SUCCESS;
    }
}

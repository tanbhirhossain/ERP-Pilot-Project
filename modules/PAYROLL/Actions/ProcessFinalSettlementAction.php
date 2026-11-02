<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollSettlement;

final class ProcessFinalSettlementAction
{
    public function __construct(private readonly EmployeePayrollDataProviderInterface $employees) {}

    public function execute(array $data, ?int $actorId): PayrollSettlement
    {
        return DB::transaction(function () use ($data, $actorId): PayrollSettlement {
            // Lock the employee row to serialize final-settlement requests for this employee.
            DB::table('employees')->where('employee_id', $data['employee_id'])->lockForUpdate()->first();
            $alreadySettled = PayrollSettlement::query()
                ->where('employee_id', $data['employee_id'])
                ->whereIn('status', ['pending', 'approved', 'paid'])
                ->exists();
            if ($alreadySettled) {
                throw ValidationException::withMessages(['employee_id' => 'An active or paid final settlement already exists for this employee.']);
            }

            return $this->calculateAndCreate($data, $actorId);
        }, 3);
    }

    private function calculateAndCreate(array $data, ?int $actorId): PayrollSettlement
    {
        $employee = $this->employees->employeeForSettlement(
            $data['employee_id'],
            Carbon::parse($data['last_working_date'])->startOfMonth()->toDateString(),
            $data['last_working_date'],
        );
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'HRM has no effective salary record for this settlement period.']);
        }

        $lastDay = Carbon::parse($data['last_working_date'])->startOfDay();
        if ($employee->joiningDate !== null && $lastDay->lt(Carbon::parse($employee->joiningDate)->startOfDay())) {
            throw ValidationException::withMessages(['last_working_date' => 'The last working date cannot be earlier than the employee’s joining date.']);
        }
        $monthStart = $lastDay->copy()->startOfMonth();
        $monthDays = $lastDay->daysInMonth;
        $firstPayableDay = $employee->joiningDate ? Carbon::parse($employee->joiningDate) : $monthStart->copy();
        if ($firstPayableDay->lt($monthStart)) $firstPayableDay = $monthStart->copy();
        $payableDays = $firstPayableDay->gt($lastDay) ? 0 : $firstPayableDay->diffInDays($lastDay) + 1;
        $salarySegments = $employee->salarySegments;
        $grossDue = round(collect($salarySegments)->sum(fn (array $segment): float => (float) $segment['gross_salary'] * (int) $segment['days'] / $monthDays), 2);
        $lastSalarySegment = collect($salarySegments)->last() ?? [];
        $serviceYears = $employee->joiningDate && Carbon::parse($employee->joiningDate)->lte($lastDay)
            ? Carbon::parse($employee->joiningDate)->diffInYears($lastDay)
            : 0;
        $policy = GratuityPolicy::query()->where('is_active', true)->latest('id')->first();
        $gratuity = 0.0;
        if ($policy && $serviceYears >= (int) $policy->minimum_service_years) {
            $base = (float) ($lastSalarySegment[$policy->calculation_base === 'gross' ? 'gross_salary' : 'basic_salary'] ?? 0);
            $gratuity = round(($base / 30) * (float) $policy->days_per_service_year * $serviceYears, 2);
        }

        $leaveEncashment = (float) ($data['leave_encashment'] ?? 0);
        $otherBenefits = (float) ($data['other_benefits'] ?? 0);
        $deductions = (float) ($data['deductions'] ?? 0);
        $net = round($grossDue + $gratuity + $leaveEncashment + $otherBenefits - $deductions, 2);
        $breakdown = [
            'salary_proration' => ['rule' => 'calendar-day weighted by effective salary segment', 'days_in_month' => $monthDays, 'payable_days' => $payableDays, 'salary_segments' => $salarySegments, 'gross_due' => $grossDue],
            'service_years' => $serviceYears,
            'gratuity_policy_id' => $policy?->id,
            'gratuity' => $gratuity,
            'leave_encashment' => $leaveEncashment,
            'other_benefits' => $otherBenefits,
            'deductions' => $deductions,
            'manual_adjustments' => [
                'leave_encashment_reason' => $data['leave_encashment_reason'] ?? null,
                'other_benefits_reason' => $data['other_benefits_reason'] ?? null,
                'deductions_reason' => $data['deductions_reason'] ?? null,
            ],
            'net_settlement' => $net,
        ];

        return PayrollSettlement::query()->create([
            'employee_id' => $employee->employeeId,
            'employee_name' => $employee->name,
            'settlement_date' => $data['settlement_date'],
            'last_working_date' => $data['last_working_date'],
            'gross_due' => $grossDue,
            'gratuity_amount' => $gratuity,
            'leave_encashment' => $leaveEncashment,
            'other_benefits' => $otherBenefits,
            'deductions' => $deductions,
            'net_settlement' => $net,
            'status' => 'pending',
            'breakdown_json' => $breakdown,
            'created_by' => $actorId,
        ]);
    }
}

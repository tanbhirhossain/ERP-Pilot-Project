<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollCutlistItem;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Illuminate\Validation\ValidationException;

final class GenerateSalaryCutlistAction
{
    public function __construct(
        private readonly EmployeePayrollDataProviderInterface $employees,
        private readonly AttendancePayrollDataProviderInterface $attendance,
    ) {}

    public function execute(int $year, int $month, ?int $actorId = null, bool $duringPayrollRun = false, ?array $employeeSnapshot = null): PayrollCutlist
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $sheet = PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->first();
        if ($sheet && in_array($sheet->status, ['ready','released'], true)) {
            throw ValidationException::withMessages(['cutlist' => 'A completed or released salary sheet cannot have its source cutlist recalculated.']);
        }
        if ($sheet && in_array($sheet->status, ['queued','processing'], true) && !$duringPayrollRun) {
            throw ValidationException::withMessages(['cutlist' => 'The salary sheet is queued or processing; wait until it completes before refreshing the cutlist.']);
        }
        if ($sheet && $sheet->status === 'failed' && !$duringPayrollRun
            && \Modules\PAYROLL\Models\PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->exists()) {
            throw ValidationException::withMessages(['cutlist' => 'This failed sheet already contains partial slips. Retry the same salary sheet so its original cutlist snapshot is preserved.']);
        }
        $settings = PayrollSetting::query()->first();
        $divisor = max(1, (float) ($settings?->unpaid_leave_day_divisor ?? 30));
        $punishmentThreshold = max(1, (int) ($settings?->punishment_days_per_salary_day ?? 3));
        $otMultiplier = (float) ($settings?->overtime_multiplier ?? 1.0);
        $extraMultiplier = (float) ($settings?->extra_duty_multiplier ?? 1.0);
        $cutlist = PayrollCutlist::query()->firstOrCreate(
            ['pay_year' => $year, 'pay_month' => $month],
            ['status' => 'draft', 'created_by' => $actorId],
        );

        if ($cutlist->status === 'locked') {
            throw new \RuntimeException('This salary cutlist has been locked.');
        }
        // A recalculation is a full period rebuild; remove rows for employees who are no longer eligible.
        DB::transaction(function () use ($cutlist): void {
            $cutlist->items()->delete();
            $cutlist->update(['status' => 'draft', 'generated_at' => null]);
        });

        $employees = $employeeSnapshot !== null
            ? collect($employeeSnapshot)
            : $this->employees->employeesForPayroll($start->toDateString(), $end->toDateString());
        foreach ($employees->chunk(50) as $employeeChunk) {
            DB::transaction(function () use ($cutlist, $employeeChunk, $start, $end, $divisor, $settings, $punishmentThreshold, $otMultiplier, $extraMultiplier): void {
                $summaries = $this->attendance->summariesForEmployees($employeeChunk->pluck('employeeId')->all(), $start->toDateString(), $end->toDateString());
                foreach ($employeeChunk as $employee) {
                    $summary = $summaries[$employee->employeeId];
                    $dailyRate = round($employee->grossSalary / $divisor, 2);
                    $hourlyBasic = $summary->dutyHours > 0 ? $employee->basicSalary / (30 * $summary->dutyHours) : 0;
                    $punishmentDayRate = $employee->grossSalary / 30;
                    $punishmentDeduction = $summary->punishable ? floor($summary->punishmentDays / $punishmentThreshold) * $punishmentDayRate : 0;
                    $overtimePay = $hourlyBasic * $summary->approvedOvertimeHours * $otMultiplier;
                    $extraDutyPay = $hourlyBasic * $summary->approvedExtraDutyHours * $extraMultiplier;
                    PayrollCutlistItem::query()->updateOrCreate(
                        ['payroll_cutlist_id' => $cutlist->id, 'employee_id' => $employee->employeeId],
                        [
                            'employee_name' => $employee->name,
                            'unpaid_leave_days' => $summary->unpaidLeaveDays,
                            'late_count' => $summary->lateCount,
                            'early_out_count' => $summary->earlyOutCount,
                            'punishment_days' => $summary->punishmentDays,
                            'punishable' => $summary->punishable,
                            'approved_overtime_minutes' => (int) round($summary->approvedOvertimeHours * 60),
                            'approved_extra_duty_minutes' => (int) round($summary->approvedExtraDutyHours * 60),
                            'missing_punch_count' => $summary->missingPunchCount,
                            'unpaid_leave_deduction' => round($dailyRate * $summary->unpaidLeaveDays, 2),
                            'late_penalty_deduction' => round($punishmentDeduction, 2),
                            'missing_punch_deduction' => round($summary->missingPunchCount * (float) ($settings?->missing_punch_penalty_amount ?? 0), 2),
                            'overtime_pay' => round($overtimePay, 2),
                            'extra_duty_pay' => round($extraDutyPay, 2),
                            'source_breakdown' => array_merge($summary->sourceBreakdown, [
                                'calculation_details' => [
                                    'punishment' => ['eligible' => $summary->punishable, 'affected_days' => $summary->punishmentDays, 'days_per_salary_day' => $punishmentThreshold, 'salary_day_rate' => round($punishmentDayRate, 2), 'deduction' => round($punishmentDeduction, 2)],
                                    'overtime' => ['approved_hours' => $summary->approvedOvertimeHours, 'duty_hours' => $summary->dutyHours, 'hourly_basic' => round($hourlyBasic, 4), 'multiplier' => $otMultiplier, 'pay' => round($overtimePay, 2)],
                                    'extra_duty' => ['approved_hours' => $summary->approvedExtraDutyHours, 'duty_hours' => $summary->dutyHours, 'hourly_basic' => round($hourlyBasic, 4), 'multiplier' => $extraMultiplier, 'pay' => round($extraDutyPay, 2)],
                                ],
                            ]),
                        ],
                    );
                }
            }, 3);
        }
        $cutlist->update(['status' => 'generated', 'generated_at' => now()]);

        return $cutlist->refresh();
    }
}

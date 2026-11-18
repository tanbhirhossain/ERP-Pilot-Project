<?php

namespace Modules\PAYROLL\Services\HRM;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\LeaveBalanceService;
use Modules\PAYROLL\DTO\AttendancePayrollSummary;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;

final class HrmAttendancePayrollDataProvider implements AttendancePayrollDataProviderInterface
{
    public function __construct(private readonly LeaveBalanceService $leaveBalances) {}

    public function summaryForEmployee(string $employeeId, string $periodStart, string $periodEnd): AttendancePayrollSummary
    {
        return $this->summariesForEmployees([$employeeId], $periodStart, $periodEnd)[$employeeId];
    }

    /** @param array<int, string> $employeeIds
     * @return array<string, AttendancePayrollSummary>
     */
    public function summariesForEmployees(array $employeeIds, string $periodStart, string $periodEnd): array
    {
        $employeeIds = array_values(array_unique(array_map('strval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $employees = Employee::query()->with(['department', 'policy'])->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');
        $rowsByEmployee = AttendanceDaily::query()->whereIn('employee_id', $employeeIds)
            ->whereBetween('duty_date', [$periodStart, $periodEnd])->get(['employee_id', 'duty_date', 'status', 'remarks'])->groupBy('employee_id');
        $leaveByEmployee = LeaveRequest::query()->with('leaveType')
            ->whereIn('employee_id', $employeeIds)->where('status', 'Approved')
            ->whereDate('from_date', '<=', $periodEnd)->whereDate('to_date', '>=', $periodStart)
            ->get()->groupBy('employee_id');

        $departmentIds = $employees->pluck('department_id')->filter()->unique()->values()->all();
        $holidayRows = Holiday::query()->whereBetween('holiday_date', [$periodStart, $periodEnd])
            ->where(fn ($query) => $query->whereNull('department_id')->orWhereIn('department_id', $departmentIds))
            ->get(['holiday_date', 'department_id']);
        $globalHolidays = $holidayRows->whereNull('department_id')->map(fn ($row): string => Carbon::parse($row->holiday_date)->toDateString())->unique()->values()->all();
        $holidaysByDepartment = $holidayRows->whereNotNull('department_id')->groupBy('department_id')
            ->map(fn ($rows): array => $rows->map(fn ($row): string => Carbon::parse($row->holiday_date)->toDateString())->unique()->values()->all())->all();

        $approvedRows = $this->approvedOvertimeRows($employeeIds, $periodStart, $periodEnd)->groupBy('employee_id');
        $daysInPeriod = iterator_count(CarbonPeriod::create($periodStart, $periodEnd));

        // ── Absence deduction: what the day *should* have been, versus what it was ──
        //
        // Until now this method deducted only unpaid *leave*, which is the smallest part of the
        // absence problem and the only part that needed paperwork. A day the employee did not turn
        // up for, with a roster duty waiting, was simply paid — 17,195 days in October 2026 alone,
        // and payroll's own test suite pinned that behaviour in place.
        //
        // The distinction that makes this safe to switch on: a day is only deducted when the
        // employee was demonstrably at work in this period. Someone with zero punches is not
        // evidence of absence, it is evidence of a missing device export — docking 14,210 days from
        // 490 people because nobody uploaded their punches would be a payroll error, not discipline.
        // So the gate is "did this device report them at least once in the period".
        $punchedEmployeeIds = collect(DB::table('attendance_logs')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('punch_time', [$periodStart.' 00:00:00', $periodEnd.' 23:59:59'])
            ->distinct()->pluck('employee_id'))
            ->mapWithKeys(fn ($id): array => [(string) $id => true])->all();

        $fallbackOffDays = DB::table('employee_fallback_off_days')->whereIn('employee_id', $employeeIds)
            ->pluck('day_of_week', 'employee_id')->all();

        $rosterOffDays = [];
        $rosterScheduled = [];
        foreach (RosterDuty::query()->whereIn('employee_id', $employeeIds)
            ->whereBetween('roster_date', [$periodStart, $periodEnd])
            ->get(['employee_id', 'roster_date', 'status']) as $duty) {
            $date = Carbon::parse($duty->roster_date)->toDateString();
            if ($duty->status === 'Off') {
                $rosterOffDays[$duty->employee_id][$date] = true;
            } else {
                $rosterScheduled[$duty->employee_id][$date] = true;
            }
        }
        $result = [];

        foreach ($employeeIds as $employeeId) {
            /** @var Employee|null $employee */
            $employee = $employees->get($employeeId);
            $rows = $rowsByEmployee->get($employeeId, collect());
            $lateCount = $rows->whereIn('status', ['Late', 'Late & Early Out'])->count();
            $earlyOutCount = $rows->whereIn('status', ['Early Out', 'Late & Early Out'])->count();
            $punishmentDays = $rows->whereIn('status', ['Late', 'Early Out', 'Late & Early Out'])->count();
            $missingCount = $rows->whereIn('status', ['Missing In', 'Missing Out'])->count();
            $unpaidDays = 0.0;
            $unpaidLeaveEntries = [];

            foreach ($leaveByEmployee->get($employeeId, collect()) as $leave) {
                if ($leave->leaveType?->is_paid !== false || $employee === null) {
                    continue;
                }
                $from = Carbon::parse($leave->from_date)->max(Carbon::parse($periodStart));
                $to = Carbon::parse($leave->to_date)->min(Carbon::parse($periodEnd));
                if ($from->year !== $to->year) {
                    continue;
                }
                $isHalfDay = (bool) $leave->is_half_day && $from->isSameDay($to);
                $employeeHolidays = array_values(array_unique(array_merge($globalHolidays, $holidaysByDepartment[$employee->department_id] ?? [])));
                $leaveDays = $this->leaveBalances->days(
                    $employeeId,
                    (int) $leave->leave_type_id,
                    $from->toDateString(),
                    $to->toDateString(),
                    $isHalfDay,
                    $leave->leaveType,
                    $employee,
                    $employeeHolidays,
                );
                $unpaidDays += $leaveDays;
                $unpaidLeaveEntries[] = ['leave_request_id' => $leave->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => round($leaveDays, 2)];
            }

            $employeeApprovedRows = $approvedRows->get($employeeId, collect());
            $approvedOvertimeHours = (float) $employeeApprovedRows->where('overtime_type', 'Overtime')->sum('hours');
            $approvedExtraDutyHours = (float) $employeeApprovedRows->whereIn('overtime_type', ['Extra Duty', 'Holiday Duty'])->sum('hours');
            // ── Absent days with no leave paperwork ──
            $absentDays = 0.0;
            $absentDates = [];
            $holidaysForEmployee = array_values(array_unique(array_merge($globalHolidays, $holidaysByDepartment[$employee?->department_id] ?? [])));
            $fallbackDay = $fallbackOffDays[$employeeId] ?? null;

            if ($employee !== null && isset($punchedEmployeeIds[$employeeId])) {
                $leaveDates = [];
                foreach ($leaveByEmployee->get($employeeId, collect()) as $leave) {
                    for ($d = Carbon::parse($leave->from_date); $d->lte(Carbon::parse($leave->to_date)); $d->addDay()) {
                        $leaveDates[$d->toDateString()] = true;
                    }
                }

                for ($day = Carbon::parse($periodStart); $day->lte(Carbon::parse($periodEnd)); $day->addDay()) {
                    if ($employee->joining_date !== null && Carbon::parse($employee->joining_date)->gt($day)) {
                        continue;
                    }
                    $date = $day->toDateString();
                    if (in_array($date, $holidaysForEmployee, true)
                        || isset($leaveDates[$date])
                        || isset($rosterOffDays[$employeeId][$date])
                        || ($fallbackDay !== null && (int) $fallbackDay === (int) $day->dayOfWeek)) {
                        continue;
                    }

                    // A day with no roster duty is not evidence the employee was expected in; an
                    // explicit duty is, and so is the attendance row the engine already produced.
                    $status = $rows->firstWhere(fn ($row): bool => Carbon::parse($row->duty_date)->toDateString() === $date)?->status;
                    if ($status === 'Absent') {
                        $absentDays += 1.0;
                        $absentDates[] = $date;

                        continue;
                    }
                    if ($status === null && isset($rosterScheduled[$employeeId][$date])) {
                        $absentDays += 1.0;
                        $absentDates[] = $date;
                    }
                }
            }

            $punishable = (bool) ($employee?->policy?->is_punishable ?? true);
            $dutyHours = max(1, (int) ($employee?->policy?->duty_hours ?? $employee?->duty_hours ?? 8));

            $result[$employeeId] = new AttendancePayrollSummary(
                unpaidLeaveDays: min((float) $daysInPeriod, round($unpaidDays + $absentDays, 2)),
                lateCount: $lateCount,
                earlyOutCount: $earlyOutCount,
                punishmentDays: $punishmentDays,
                punishable: $punishable,
                missingPunchCount: $missingCount,
                approvedOvertimeHours: $approvedOvertimeHours,
                approvedExtraDutyHours: $approvedExtraDutyHours,
                dutyHours: $dutyHours,
                sourceBreakdown: [
                    'attendance_daily_rows' => $rows->count(),
                    'late_dates' => $rows->whereIn('status', ['Late', 'Late & Early Out'])->pluck('duty_date')->map(fn ($date): string => Carbon::parse($date)->toDateString())->values()->all(),
                    'early_out_dates' => $rows->whereIn('status', ['Early Out', 'Late & Early Out'])->pluck('duty_date')->map(fn ($date): string => Carbon::parse($date)->toDateString())->values()->all(),
                    'punishment_days' => $punishmentDays,
                    'punishable' => $punishable,
                    'approved_overtime_hours' => $approvedOvertimeHours,
                    'approved_extra_duty_hours' => $approvedExtraDutyHours,
                    'approved_overtime_records' => $employeeApprovedRows->where('overtime_type', 'Overtime')->map(fn ($row) => ['date' => Carbon::parse($row->overtime_date)->toDateString(), 'hours' => (float) $row->hours])->values()->all(),
                    'approved_extra_duty_records' => $employeeApprovedRows->whereIn('overtime_type', ['Extra Duty', 'Holiday Duty'])->map(fn ($row) => ['date' => Carbon::parse($row->overtime_date)->toDateString(), 'hours' => (float) $row->hours])->values()->all(),
                    'duty_hours' => $dutyHours,
                    'missing_punch_dates' => $rows->whereIn('status', ['Missing In', 'Missing Out'])->pluck('duty_date')->map(fn ($date): string => Carbon::parse($date)->toDateString())->values()->all(),
                    'holiday_dates' => array_values(array_unique(array_merge($globalHolidays, $holidaysByDepartment[$employee?->department_id] ?? []))),
                    'unpaid_leave_dates' => array_values(array_map(fn ($entry): string => (string) $entry['from'], $unpaidLeaveEntries)),
                    'absent_dates' => $absentDates,
                    'absent_days' => round($absentDays, 2),
                    'absence_gated_by_punch' => isset($punchedEmployeeIds[$employeeId]),
                    'unpaid_leave_requests' => $unpaidLeaveEntries,
                ],
            );
        }

        return $result;
    }

    /** @param array<int, string> $employeeIds */
    private function approvedOvertimeRows(array $employeeIds, string $periodStart, string $periodEnd): Collection
    {
        return RosterOvertime::query()->whereIn('employee_id', $employeeIds)
            ->whereBetween('overtime_date', [$periodStart, $periodEnd])->where('status', 'Approved')
            ->where(function ($query): void {
                $query->where(function ($ot): void {
                    $ot->where('overtime_type', 'Overtime')->whereExists(function ($daily): void {
                        $daily->selectRaw('1')->from('attendance_dailies')
                            ->whereColumn('attendance_dailies.employee_id', 'roster_overtimes.employee_id')
                            ->whereColumn('attendance_dailies.duty_date', 'roster_overtimes.overtime_date')
                            ->where('attendance_dailies.total_overtime_hours', '>', 0.5);
                    });
                })->orWhere(function ($extra): void {
                    $extra->whereIn('overtime_type', ['Extra Duty', 'Holiday Duty'])->whereExists(function ($daily): void {
                        $daily->selectRaw('1')->from('attendance_dailies')
                            ->whereColumn('attendance_dailies.employee_id', 'roster_overtimes.employee_id')
                            ->whereColumn('attendance_dailies.duty_date', 'roster_overtimes.overtime_date')
                            ->where('attendance_dailies.status', 'Extra Duty');
                    });
                });
            })->get(['employee_id', 'overtime_type', 'hours', 'overtime_date']);
    }
}

<?php

namespace Modules\HRM\Services\Attendance;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Roster\RosterDuty;

final class AttendanceDayReconciliationService
{
    /** Reconcile one attendance classification per employee/calendar date; never edits pair/auto-shift decisions. */
    public function reconcile(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay()->min(now()->subDay()->startOfDay());
        if ($start->gt($end)) return ['created' => 0, 'absent' => 0, 'holiday' => 0, 'leave' => 0, 'off_day' => 0, 'night_pending' => 0];
        $dates = collect(CarbonPeriod::create($start, $end))->map(fn (Carbon $date) => $date->toDateString())->values();
        $totals = ['created' => 0, 'absent' => 0, 'holiday' => 0, 'leave' => 0, 'off_day' => 0, 'night_pending' => 0];

        Employee::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($employees) use ($dates, $start, $end, &$totals): void {
            $ids = $employees->pluck('employee_id')->all();
            $fallbackOffDays = EmployeeFallbackOffDay::query()->whereIn('employee_id', $ids)->pluck('day_of_week', 'employee_id');
            $duties = RosterDuty::query()->with('shift:id,crosses_midnight,start_time,end_time')->whereIn('employee_id', $ids)->whereBetween('roster_date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($d) => $d->employee_id.'|'.$d->roster_date?->toDateString());
            $existingRows = AttendanceDaily::query()->whereIn('employee_id', $ids)->whereBetween('duty_date', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
            $existing = $this->deduplicateDailyRows($existingRows);
            $pairs = AttendancePair::query()->with('shift:id,crosses_midnight,start_time,end_time')->whereIn('employee_id', $ids)->whereBetween('duty_date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])->get();
            $pairKeys = $pairs->keyBy(fn ($pair) => $pair->employee_id.'|'.$pair->duty_date?->toDateString());
            // Raw logs can arrive before pairing completes. Never create an Absent/Holiday/Leave
            // row for a calendar date that already has a biometric punch awaiting processing.
            $rawLogKeys = [];
            $deviceUsers = DeviceUser::query()->whereIn('employee_id', $ids)->get(['device_user_id','employee_id']);
            $deviceUserToEmployee = $deviceUsers->mapWithKeys(fn ($user) => [(string) $user->device_user_id => $user->employee_id])->all();
            foreach ($employees as $employee) if ($employee->biometric_id) $deviceUserToEmployee[(string) $employee->biometric_id] = $employee->employee_id;
            $deviceUserIds = array_keys($deviceUserToEmployee);
            AttendanceLog::query()->whereBetween('punch_time', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->where(function ($query) use ($ids, $deviceUserIds): void {
                    $query->whereIn('employee_id', $ids);
                    if ($deviceUserIds) $query->orWhereIn('device_user_id', $deviceUserIds);
                })
                ->get(['employee_id', 'device_user_id', 'punch_time'])
                ->each(function (AttendanceLog $log) use (&$rawLogKeys, $deviceUserToEmployee): void {
                    $employeeId = $log->employee_id ?: ($deviceUserToEmployee[(string) $log->device_user_id] ?? null);
                    if ($employeeId) $rawLogKeys[$employeeId.'|'.$log->punch_time?->toDateString()] = true;
                });
            $nightPending = [];
            foreach ($pairs->whereNull('punch_out') as $pair) {
                $shift = $pair->shift;
                $crosses = (bool) ($shift?->crosses_midnight) || ($shift?->start_time && $shift?->end_time && substr((string) $shift->end_time, 0, 8) < substr((string) $shift->start_time, 0, 8));
                if ($crosses && $pair->punch_in) $nightPending[$pair->employee_id.'|'.$pair->duty_date?->copy()->addDay()->toDateString()] = true;
            }
            $departmentIds = $employees->pluck('department_id')->filter()->unique()->all();
            $holidays = Holiday::query()->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
                ->where(function ($q) use ($departmentIds): void { $q->whereNull('department_id'); if ($departmentIds) $q->orWhereIn('department_id', $departmentIds); })
                ->get(['holiday_date', 'department_id']);
            $holidayKeys = [];
            foreach ($holidays as $holiday) $holidayKeys[$holiday->holiday_date->toDateString().'|'.($holiday->department_id ?? '*')] = true;
            $leaves = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', 'Approved')
                ->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $start->toDateString())->get(['employee_id','from_date','to_date']);
            $resignations = EmployeeResignation::query()->whereIn('employee_id', $ids)->where('status','Approved')->get(['employee_id','last_working_date'])->keyBy('employee_id');
            $rows = [];

            foreach ($employees as $employee) {
                $departmentId = $employee->department_id;
                $resigned = $resignations->get($employee->employee_id)?->last_working_date?->toDateString();
                foreach ($dates as $date) {
                    $key = $employee->employee_id.'|'.$date;
                    if ($resigned && $date > $resigned) continue;
                    $duty = $duties->get($key);
                    $fallbackOff = !$duty && $fallbackOffDays->has($employee->employee_id) && Carbon::parse($date)->dayOfWeek === (int) $fallbackOffDays->get($employee->employee_id);
                    $isOffDay = $duty?->status === 'Off' || $fallbackOff;
                    $onLeave = $leaves->contains(fn ($leave) => $leave->employee_id === $employee->employee_id && $date >= $leave->from_date->toDateString() && $date <= $leave->to_date->toDateString());
                    $isHoliday = isset($holidayKeys[$date.'|*']) || ($departmentId && isset($holidayKeys[$date.'|'.$departmentId]));
                    $daily = $existing[$key] ?? null;
                    $hasPunch = $daily && ($daily->primary_punch_in || $daily->primary_punch_out);
                    $hasLogOrPair = isset($rawLogKeys[$key]) || isset($pairKeys[$key]);
                    if ($daily && $hasPunch && in_array($daily->status, ['Absent','Holiday','On Leave','Off Day'], true)) {
                        $correctedStatus = ($isOffDay || $isHoliday)
                            ? 'Extra Duty'
                            : ($daily->primary_punch_in && !$daily->primary_punch_out ? 'Missing Out' : (!$daily->primary_punch_in && $daily->primary_punch_out ? 'Missing In' : 'Present'));
                        $daily->update(['status'=>$correctedStatus,'remarks'=>'Reconciled from attendance punches and roster/holiday records.']);
                    }
                    // Reconcile stale no-log rows against current source-of-truth records.
                    // Punch evidence removes stale synthetic classifications; the pipeline then
                    // writes the punch-based status. Leave/Holiday outrank roster Off; otherwise
                    // Off Day is stored as the calendar-day classification.
                    if ($daily && !$hasPunch && in_array($daily->status, ['Absent','Holiday','On Leave','Off Day'], true)) {
                        if ($hasLogOrPair) {
                            $daily->delete();
                            unset($existing[$key]);
                            $daily = null;
                        } elseif ($onLeave || $isHoliday) {
                            $status = $onLeave ? 'On Leave' : 'Holiday';
                            if ($daily->status !== $status) $daily->update(['status'=>$status,'remarks'=>$status === 'Holiday' ? 'No attendance logs on holiday.' : 'No attendance logs during approved leave.']);
                        } elseif ($isOffDay) {
                            if ($daily->status !== 'Off Day') $daily->update(['status'=>'Off Day','remarks'=>'No attendance logs on roster Off day.']);
                        } elseif ($daily->status !== 'Absent') {
                            $daily->update(['status'=>'Absent','remarks'=>'No attendance logs for scheduled workday.']);
                        }
                    }
                    if (isset($existing[$key]) || isset($pairKeys[$key]) || isset($rawLogKeys[$key])) continue;
                    if (isset($nightPending[$key])) { $totals['night_pending']++; continue; }
                    if ($this->scheduledNightShiftStillOpen($duty, $date)) { $totals['night_pending']++; continue; }
                    $status = $onLeave ? 'On Leave' : ($isHoliday ? 'Holiday' : ($isOffDay ? 'Off Day' : 'Absent'));
                    $rows[] = [
                        'employee_id' => $employee->employee_id, 'duty_date' => $date,
                        'primary_shift_id' => $duty?->shift_id,
                        'expected_in_time' => $duty?->scheduled_start_time,
                        'expected_out_time' => $duty?->scheduled_end_time,
                        'primary_worked_hours' => 0, 'total_overtime_hours' => 0, 'grand_total_hours' => 0,
                        'status' => $status,
                        'remarks' => match ($status) { 'Absent' => 'No attendance logs for scheduled workday.', 'Holiday' => 'No attendance logs on holiday.', 'On Leave' => 'No attendance logs during approved leave.', default => 'No attendance logs on roster Off day.' },
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $counter = match ($status) {
                        'Absent' => 'absent',
                        'Holiday' => 'holiday',
                        'On Leave' => 'leave',
                        'Off Day' => 'off_day',
                        default => null,
                    };
                    if ($counter !== null) $totals[$counter] = ($totals[$counter] ?? 0) + 1;
                }
            }
            if ($rows) {
                DB::transaction(fn () => AttendanceDaily::query()->insertOrIgnore($rows), 3);
                $totals['created'] += count($rows);
            }
        }, 'id');

        return $totals;
    }

    /**
     * Repair legacy duplicate rows for the same employee/calendar date. Keep the row with
     * real punch data (rather than a stale synthetic Absent row), merge non-empty fields,
     * and remove redundant rows so the calendar-day invariant is restored without a schema change.
     */
    private function deduplicateDailyRows($rows): array
    {
        $canonical = [];
        foreach ($rows->groupBy(fn ($row) => $row->employee_id.'|'.$row->duty_date?->toDateString()) as $key => $group) {
            $rank = static function ($row): array {
                $punches = (int) (bool) $row->primary_punch_in + (int) (bool) $row->primary_punch_out;
                $hours = (float) ($row->grand_total_hours ?? 0);
                $statusRank = match ($row->status) {
                    'Extra Duty', 'Present', 'Late', 'Early Out', 'Late & Early Out', 'Missing In', 'Missing Out' => 3,
                    'Holiday' => 3,
                    'On Leave' => 2,
                    'Off Day' => 1,
                    default => 0,
                };
                return [$punches, $hours, $statusRank, -(int) $row->id];
            };
            $keeper = $group->sortByDesc($rank)->first();
            if ($group->count() > 1) {
                DB::transaction(function () use ($group, $keeper): void {
                    AttendanceDaily::query()->whereKey($keeper->id)->lockForUpdate()->first();
                    $duplicates = AttendanceDaily::query()->whereIn('id', $group->pluck('id')->reject(fn ($id) => (int) $id === (int) $keeper->id)->all())->lockForUpdate()->get();
                    foreach ($duplicates as $duplicate) {
                        if (!$keeper->primary_punch_in || ($duplicate->primary_punch_in && $duplicate->primary_punch_in < $keeper->primary_punch_in)) $keeper->primary_punch_in = $duplicate->primary_punch_in;
                        if (!$keeper->primary_punch_out || ($duplicate->primary_punch_out && $duplicate->primary_punch_out > $keeper->primary_punch_out)) $keeper->primary_punch_out = $duplicate->primary_punch_out;
                        foreach (['primary_shift_id','expected_in_time','expected_out_time'] as $field) if (!$keeper->{$field} && $duplicate->{$field}) $keeper->{$field} = $duplicate->{$field};
                        foreach (['primary_worked_hours','total_overtime_hours','grand_total_hours'] as $field) $keeper->{$field} = max((float) ($keeper->{$field} ?? 0), (float) ($duplicate->{$field} ?? 0));
                        if (!$keeper->remarks && $duplicate->remarks) $keeper->remarks = $duplicate->remarks;
                    }
                    // A row carrying punches must never retain a synthetic no-log classification.
                    if ($keeper->primary_punch_in || $keeper->primary_punch_out) {
                        if (in_array($keeper->status, ['Absent','Holiday','On Leave','Off Day'], true)) $keeper->status = $keeper->primary_punch_in && !$keeper->primary_punch_out ? 'Missing Out' : ($keeper->primary_punch_out && !$keeper->primary_punch_in ? 'Missing In' : 'Present');
                    } else {
                        $statuses = $duplicates->pluck('status')->prepend($keeper->status);
                        foreach (['Holiday','On Leave','Off Day','Absent'] as $preferred) if ($statuses->contains($preferred)) { $keeper->status = $preferred; break; }
                    }
                    $keeper->save();
                    AttendanceDaily::query()->whereIn('id', $duplicates->modelKeys())->delete();
                }, 3);
            }
            $canonical[$key] = $keeper;
        }
        return $canonical;
    }

    private function scheduledNightShiftStillOpen(?RosterDuty $duty, string $date): bool
    {
        if ($duty === null || $date !== now()->subDay()->toDateString()) return false;
        $shift = $duty->shift;
        $start = $duty->scheduled_start_time ?: $shift?->start_time;
        $expectedEnd = $duty->scheduled_end_time ?: $shift?->end_time;
        $customCrosses = $duty->custom_start_datetime && $duty->custom_end_datetime
            && $duty->custom_end_datetime->toDateString() > $duty->custom_start_datetime->toDateString();
        $crosses = (bool) ($shift?->crosses_midnight) || (bool) $customCrosses
            || ($start && $expectedEnd && substr((string) $expectedEnd, 0, 8) < substr((string) $start, 0, 8));
        if (!$crosses) return false;
        if (!$expectedEnd && $duty->custom_end_datetime) $expectedEnd = $duty->custom_end_datetime->format('H:i:s');
        if (!$expectedEnd) return true;
        return now()->format('H:i:s') < substr((string) $expectedEnd, 0, 8);
    }
}

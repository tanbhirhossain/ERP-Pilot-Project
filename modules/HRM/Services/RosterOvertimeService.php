<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\Attendance\OffDayResolver;
use Modules\PAYROLL\Models\PayrollAttendanceDutyDecision;
use Modules\PAYROLL\Models\PayrollSalarySheet;

class RosterOvertimeService
{
    public function __construct(
        private readonly RosterOvertimeRepositoryInterface $repo,
        private readonly OffDayResolver $offDays,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function attendanceApprovalQueue(DataTableQuery $dt, string $kind): LengthAwarePaginator
    {
        return $this->repo->attendanceApprovalQueue($dt, $kind);
    }

    /**
     * Footer totals for the whole filtered set, not just the visible page.
     *
     * A page-local sum moves every time the operator pages, which makes it useless for the one
     * question the footer is asked: "how many hours am I about to approve?" The hours are summed in
     * SQL so a 2,000-row queue costs one query, and the attendance column is joined on date() because
     * duty_date is stored in both 'Y-m-d' and 'Y-m-d 00:00:00' shapes.
     */
    public function queueTotals(DataTableQuery $dt, string $kind): array
    {
        $row = $this->filteredQueue($dt, $kind)->selectRaw('count(*) as days, coalesce(sum(roster_overtimes.hours), 0) as approved_hours')
            ->toBase()
            ->first();

        $attendance = $this->attendanceAggregate($dt, $kind);

        return [
            'days' => (int) ($row->days ?? 0),
            'employees' => $this->employeeCount($dt, $kind),
            'approved_hours' => round((float) ($row->approved_hours ?? 0), 2),
            'attendance_hours' => round($attendance['worked'], 2),
            'source_hours' => round($attendance['source'], 2),
        ];
    }

    /**
     * Every row of the filtered queue, for the printable sheet.
     *
     * The sheet is a document, not a screenshot, so this deliberately ignores pagination — but it is
     * capped, because an unbounded print of a 100k-row queue would be a 4,000-page PDF nobody asked for.
     */
    public function printableSheet(DataTableQuery $dt, string $kind, int $limit = 2000): array
    {
        $rows = $this->filteredQueue($dt, $kind)->orderBy('roster_overtimes.overtime_date')->orderBy('roster_overtimes.employee_id')
            ->limit($limit)
            ->get(['roster_overtimes.*']);

        $daily = $this->dailyMap($rows, $kind);

        return $rows->map(function (RosterOvertime $row) use ($daily, $kind): array {
            $key = $row->employee_id.'|'.$row->overtime_date?->toDateString();
            $day = $daily->get($key);

            return [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name ?: $row->employee_id,
                'overtime_date' => $row->overtime_date?->toDateString(),
                'overtime_type' => $row->overtime_type,
                'hours' => round((float) $row->hours, 2),
                'source_hours' => round((float) ($kind === 'Overtime' ? ($day?->total_overtime_hours ?? 0) : ($day?->grand_total_hours ?? 0)), 2),
                'worked_hours' => round((float) ($day?->grand_total_hours ?? 0), 2),
                'attendance_status' => $day?->status,
            ];
        })->all();
    }

    /**
     * Approve several queued days in one pass.
     *
     * Each row goes through `approve()` — the same transaction, the same payroll cutlist row and the
     * same refusals — so a bulk action can never be a second, laxer path to money. One row that the
     * business rules refuse (a salary sheet already exists for its month, the source day vanished)
     * is reported and skipped rather than aborting the whole selection.
     */
    public function bulkApprove(array $ids, ?int $actorId, ?string $comments = null): array
    {
        $approved = 0;
        $skipped = [];

        foreach (RosterOvertime::query()->whereIn('id', $ids)->orderBy('id')->get() as $row) {
            try {
                $this->approve($row, (float) $row->hours, $actorId, $comments);
                $approved++;
            } catch (ValidationException $e) {
                $skipped[] = [
                    'id' => $row->id,
                    'employee_id' => $row->employee_id,
                    'date' => $row->overtime_date?->toDateString(),
                    'reason' => collect($e->errors())->flatten()->first() ?? 'Refused.',
                ];
            }
        }

        return ['approved' => $approved, 'skipped' => $skipped, 'requested' => count($ids)];
    }

    /**
     * The filtered queue, unpaginated — one place that applies search and filters.
     *
     * `attendanceQueueQuery()` owns the queue's own predicates; this adds the operator's. The list,
     * the footer totals and the printable sheet all come through here, so they cannot disagree about
     * what "the current view" means.
     */
    private function filteredQueue(DataTableQuery $dt, string $kind): \Illuminate\Database\Eloquent\Builder
    {
        return $this->repo->applyQueueFilters($this->repo->attendanceQueueQuery($kind), $dt);
    }

    /**
     * Sum the attendance side of the same filtered set, in one statement.
     *
     * The queue rows are matched to their attendance day on `date()` — duty_date is stored in both
     * 'Y-m-d' and 'Y-m-d 00:00:00' shapes — and the join is restricted to the filtered queue by id,
     * so this counts exactly what the table is showing and not a row more.
     */
    private function attendanceAggregate(DataTableQuery $dt, string $kind): array
    {
        $idQuery = $this->filteredQueue($dt, $kind)->toBase()->select('roster_overtimes.id');

        $source = $kind === 'Overtime' ? 'attendance_dailies.total_overtime_hours' : 'attendance_dailies.grand_total_hours';

        $row = DB::table('roster_overtimes')
            ->join('attendance_dailies', function ($join): void {
                $join->on('attendance_dailies.employee_id', '=', 'roster_overtimes.employee_id')
                    ->whereRaw('date(attendance_dailies.duty_date) = date(roster_overtimes.overtime_date)');
            })
            ->whereIn('roster_overtimes.id', $idQuery)
            ->selectRaw("coalesce(sum(attendance_dailies.grand_total_hours), 0) as worked, coalesce(sum({$source}), 0) as source")
            ->first();

        return [
            'worked' => round((float) ($row->worked ?? 0), 2),
            'source' => round((float) ($row->source ?? 0), 2),
        ];
    }

    private function employeeCount(DataTableQuery $dt, string $kind): int
    {
        return (int) $this->filteredQueue($dt, $kind)
            ->toBase()
            ->distinct()
            ->count('roster_overtimes.employee_id');
    }

    /** Attendance dailies keyed 'employee|date' for a set of queue rows. */
    private function dailyMap($rows, string $kind): \Illuminate\Support\Collection
    {
        $employees = $rows->pluck('employee_id')->unique()->all();
        $dates = $rows->pluck('overtime_date')->map(fn ($d) => $d?->toDateString())->filter()->unique()->all();

        if ($employees === [] || $dates === []) {
            return collect();
        }

        return AttendanceDaily::query()
            ->whereIn('employee_id', $employees)
            ->whereIn(DB::raw('date(duty_date)'), $dates)
            ->get()
            ->keyBy(fn ($daily) => $daily->employee_id.'|'.$daily->duty_date?->toDateString());
    }

    public function findOrFail(int $id): RosterOvertime
    {
        return $this->repo->findOrFail($id);
    }

    /** Backfill approval requests from existing attendance dailies without duplicating any employee/date request. */
    public function syncFromAttendance(string $kind, ?string $from = null, ?string $to = null): array
    {
        if (! in_array($kind, ['Overtime', 'Extra Duty'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose Overtime or Extra Duty.']);
        }
        $created = 0;
        $skipped = 0;
        $dailyQuery = AttendanceDaily::query()->where('grand_total_hours', '>', 0)->whereDate('duty_date', '<=', now()->subDay()->toDateString());
        if ($kind === 'Overtime') {
            $dailyQuery->where('total_overtime_hours', '>', 0.5);
        }
        if ($from !== null) {
            $dailyQuery->whereDate('duty_date', '>=', $from);
        }
        if ($to !== null) {
            $dailyQuery->whereDate('duty_date', '<=', $to);
        }
        $dailyQuery->orderBy('id')->chunkById(400, function ($dailies) use ($kind, &$created, &$skipped): void {
            $employeeIds = $dailies->pluck('employee_id')->unique()->values()->all();
            $dates = $dailies->map(fn ($daily) => Carbon::parse($daily->duty_date)->toDateString());
            $from = $dates->min();
            $to = $dates->max();
            $employees = Employee::query()->whereIn('employee_id', $employeeIds)->get(['employee_id', 'full_name', 'allow_overtime', 'duty_hours', 'department_id'])->keyBy('employee_id');
            $rosters = RosterDuty::query()->whereIn('employee_id', $employeeIds)->whereDate('roster_date', '>=', $from)->whereDate('roster_date', '<=', $to)->get(['employee_id', 'roster_date', 'status'])->keyBy(fn ($row) => $row->employee_id.'|'.Carbon::parse($row->roster_date)->toDateString());
            $fallbackDays = EmployeeFallbackOffDay::query()->whereIn('employee_id', $employeeIds)->pluck('day_of_week', 'employee_id');
            $existingKeys = RosterOvertime::query()->whereIn('employee_id', $employeeIds)->whereDate('overtime_date', '>=', $from)->whereDate('overtime_date', '<=', $to)->get(['employee_id', 'overtime_date'])->mapWithKeys(fn ($row) => [$row->employee_id.'|'.Carbon::parse($row->overtime_date)->toDateString() => true]);
            $departmentIds = $employees->pluck('department_id')->filter()->unique()->values()->all();
            $holidays = Holiday::query()->whereDate('holiday_date', '>=', $from)->whereDate('holiday_date', '<=', $to)->where(function ($query) use ($departmentIds): void {
                $query->whereNull('department_id');
                if ($departmentIds) {
                    $query->orWhereIn('department_id', $departmentIds);
                }
            })->get(['holiday_date', 'department_id']);
            $holidayKeys = [];
            foreach ($holidays as $holiday) {
                $holidayKeys[Carbon::parse($holiday->holiday_date)->toDateString().'|'.($holiday->department_id ?? '*')] = true;
            }

            foreach ($dailies as $daily) {
                $employee = $employees->get($daily->employee_id);
                if (! $employee || ($employee->allow_overtime !== null && (int) $employee->allow_overtime === 0 && $kind === 'Overtime')) {
                    $skipped++;

                    continue;
                }
                $date = Carbon::parse($daily->duty_date)->toDateString();
                $key = $daily->employee_id.'|'.$date;
                $roster = $rosters->get($key);
                $fallbackDay = $fallbackDays->get($daily->employee_id);

                $holiday = isset($holidayKeys[$date.'|*']) || ($employee->department_id && isset($holidayKeys[$date.'|'.$employee->department_id]));
                $isOffDay = $this->offDays->isOffDayFor($roster, $daily->employee_id, $date, $fallbackDays->all());
                $isExtraDuty = $daily->status === 'Extra Duty' || $isOffDay || $holiday;
                if (($kind === 'Extra Duty' && ! $isExtraDuty) || ($kind === 'Overtime' && $isExtraDuty)) {
                    $skipped++;

                    continue;
                }
                if (isset($existingKeys[$key])) {
                    $skipped++;

                    continue;
                }
                if ($kind === 'Extra Duty') {
                    $hours = (float) $daily->grand_total_hours;
                    $type = 'Extra Duty';
                } else {
                    // Approval eligibility is driven by the attendance daily source column, not a recomputed shift-duration estimate.
                    $hours = (float) $daily->total_overtime_hours;
                    if ($hours <= 0.5) {
                        $skipped++;

                        continue;
                    }
                    $type = 'Overtime';
                }
                if ($hours <= 0 || $hours > 24) {
                    $skipped++;

                    continue;
                }
                $start = $daily->expected_out_time ? substr((string) $daily->expected_out_time, 0, 5) : null;
                if ($kind === 'Extra Duty' && $daily->primary_punch_in) {
                    $start = Carbon::parse($daily->primary_punch_in)->format('H:i');
                }
                $end = $daily->primary_punch_out ? Carbon::parse($daily->primary_punch_out)->format('H:i') : null;
                $wasCreated = DB::transaction(function () use ($daily, $date, $type, $start, $end, $hours, $kind): bool {
                    // Employee-row lock serializes concurrent queue refreshes without changing HRM schema.
                    Employee::query()->where('employee_id', $daily->employee_id)->lockForUpdate()->first();
                    if (RosterOvertime::query()->where('employee_id', $daily->employee_id)->whereDate('overtime_date', $date)->exists()) {
                        return false;
                    }
                    if ($kind === 'Extra Duty' && $daily->status !== 'Extra Duty') {
                        $daily->update(['status' => 'Extra Duty', 'remarks' => 'Reconciled as Extra Duty from attendance and off-day/holiday policy.']);
                    }
                    RosterOvertime::query()->create([
                        'employee_id' => $daily->employee_id, 'overtime_date' => $date,
                        'overtime_type' => $type, 'shift_id' => $daily->primary_shift_id,
                        'start_time' => $start, 'end_time' => $end, 'hours' => round($hours, 2),
                        'reason' => 'Backfilled from existing attendance daily record.', 'status' => 'Pending',
                    ]);

                    return true;
                }, 3);
                if (! $wasCreated) {
                    $skipped++;

                    continue;
                }
                $existingKeys[$key] = true;
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function approve(RosterOvertime $ot, float $adjustedHours, ?int $actorId, ?string $comments = null): RosterOvertime
    {
        return DB::transaction(function () use ($ot, $adjustedHours, $actorId, $comments): RosterOvertime {
            $locked = RosterOvertime::query()->whereKey($ot->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Pending') {
                throw ValidationException::withMessages(['approval' => 'Only pending attendance requests can be approved.']);
            }
            if ((int) Employee::query()->where('employee_id', $locked->employee_id)->value('user_id') === $actorId) {
                throw ValidationException::withMessages(['approval' => 'An employee cannot approve their own overtime or extra-duty record.']);
            }
            $date = $locked->overtime_date->toDateString();
            $sheetExists = PayrollSalarySheet::query()->where('pay_year', (int) Carbon::parse($date)->format('Y'))
                ->where('pay_month', (int) Carbon::parse($date)->format('m'))->exists();
            if ($sheetExists) {
                throw ValidationException::withMessages(['approval' => 'A salary sheet already exists for this attendance month. Approve attendance duty before creating payroll so the immutable cutlist includes it.']);
            }
            $daily = AttendanceDaily::query()->where('employee_id', $locked->employee_id)->whereDate('duty_date', $date)->lockForUpdate()->first();
            if ($daily === null) {
                throw ValidationException::withMessages(['approval' => 'The source attendance-daily record no longer exists.']);
            }
            $sourceHours = $locked->overtime_type === 'Overtime'
                ? (float) $daily->total_overtime_hours
                : (float) $daily->grand_total_hours;
            if ($locked->overtime_type === 'Overtime' && $sourceHours <= 0.5) {
                throw ValidationException::withMessages(['approval' => 'Overtime can only be approved when attendance daily total_overtime_hours is greater than 30 minutes.']);
            }
            if ($locked->overtime_type !== 'Overtime' && $daily->status !== 'Extra Duty') {
                throw ValidationException::withMessages(['approval' => 'Extra duty is no longer supported by the attendance-day classification.']);
            }
            $hours = round($adjustedHours, 2);
            if ($hours <= 0 || $hours > 24 || $hours > $sourceHours) {
                throw ValidationException::withMessages(['hours' => 'Approved hours must be positive, at most 24, and cannot exceed the source attendance hours.']);
            }

            PayrollAttendanceDutyDecision::query()->create([
                'roster_overtime_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'duty_date' => $date,
                'duty_type' => $locked->overtime_type,
                'attendance_source_hours' => round($sourceHours, 2),
                'requested_hours' => round((float) $locked->hours, 2),
                'approved_hours' => $hours,
                'decision' => 'approved',
                'comments' => $comments,
                'decided_by' => $actorId,
                'decided_at' => now(),
            ]);
            $locked->update(['hours' => $hours, 'status' => 'Approved', 'approved_by' => $actorId, 'approved_at' => now()]);

            return $locked->refresh();
        }, 3);
    }

    public function reject(RosterOvertime $ot, int $actorId, string $reason): RosterOvertime
    {
        return DB::transaction(function () use ($ot, $actorId, $reason): RosterOvertime {
            $locked = RosterOvertime::query()->whereKey($ot->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Pending') {
                throw ValidationException::withMessages(['approval' => 'Only pending attendance requests can be rejected.']);
            }
            if ((int) Employee::query()->where('employee_id', $locked->employee_id)->value('user_id') === $actorId) {
                throw ValidationException::withMessages(['approval' => 'An employee cannot reject their own overtime or extra-duty record.']);
            }
            $date = $locked->overtime_date->toDateString();
            if (PayrollSalarySheet::query()->where('pay_year', (int) Carbon::parse($date)->format('Y'))
                ->where('pay_month', (int) Carbon::parse($date)->format('m'))->exists()) {
                throw ValidationException::withMessages(['approval' => 'A salary sheet already exists for this attendance month.']);
            }
            $daily = AttendanceDaily::query()->where('employee_id', $locked->employee_id)->whereDate('duty_date', $date)->lockForUpdate()->first();
            $sourceHours = $daily === null ? 0.0 : ($locked->overtime_type === 'Overtime' ? (float) $daily->total_overtime_hours : (float) $daily->grand_total_hours);
            PayrollAttendanceDutyDecision::query()->create([
                'roster_overtime_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'duty_date' => $date,
                'duty_type' => $locked->overtime_type,
                'attendance_source_hours' => round($sourceHours, 2),
                'requested_hours' => round((float) $locked->hours, 2),
                'approved_hours' => null,
                'decision' => 'rejected',
                'comments' => trim($reason),
                'decided_by' => $actorId,
                'decided_at' => now(),
            ]);
            $locked->update(['status' => 'Rejected', 'approved_by' => $actorId, 'approved_at' => now()]);

            return $locked->refresh();
        }, 3);
    }
}

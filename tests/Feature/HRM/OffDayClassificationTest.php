<?php

use Carbon\Carbon;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Repositories\Attendance\AttendanceDailyRepository;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\OffDayResolver;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

/*
|--------------------------------------------------------------------------
| Off-day classification
|--------------------------------------------------------------------------
|
| Regression coverage for the bug where an employee's fallback off day was
| reported as Absent.
|
| Two independent defects combined to hide it:
|
|  1. Every off-day check was written as `!$roster && <fallback matches>`.
|     RosterDutyRepository::bulkAssign() writes a duty for EVERY calendar day
|     in the range (status 'Scheduled', with a shift) and never skips
|     weekends, so after any bulk rostering `$roster` is always set and the
|     fallback off day was silently ignored — the date fell through to
|     'Absent'.
|
|  2. The reconciler loaded duties with
|     `whereBetween('roster_date', ['Y-m-d', 'Y-m-d'])`. `roster_date` is a
|     `date` cast, which Eloquent stores as 'Y-m-d 00:00:00' on SQLite — that
|     sorts AFTER the upper bound, so the query matched nothing. On MySQL the
|     column is a real DATE and it matched fine. The test suite runs on
|     SQLite, so defect 1 was invisible: with no duty ever found, `!$roster`
|     was always true and the fallback appeared to work.
|
| Test 2 below is the one that fails if either defect returns.
*/

/** A Friday far enough in the past that reconcile() will not clamp it away. */
function offDayTestFriday(): Carbon
{
    $d = Carbon::parse('last friday')->subWeeks(2)->startOfDay();
    while ($d->dayOfWeek !== 5) {
        $d->subDay();
    }

    return $d;
}

function makeOffDayEmployee(string $employeeId = 'EMP-OFFDAY'): Employee
{
    return Employee::factory()->create([
        'employee_id' => $employeeId,
        'is_active' => true,
    ]);
}

/** Classify one employee/date and return the resulting daily status. */
function classify(Employee $employee, string $date): ?string
{
    app(AttendanceDayReconciliationService::class)->reconcile($date, $date);

    return AttendanceDaily::query()
        ->where('employee_id', $employee->employee_id)
        ->whereDate('duty_date', $date)
        ->value('status');
}

/** Reset one employee/date to a clean slate. */
function clearDay(Employee $employee, string $date): void
{
    AttendanceDaily::where('employee_id', $employee->employee_id)->whereDate('duty_date', $date)->delete();
    RosterDuty::where('employee_id', $employee->employee_id)->whereDate('roster_date', $date)->delete();
    EmployeeFallbackOffDay::where('employee_id', $employee->employee_id)->delete();
}

// ── The regression itself ─────────────────────────────────────────────────────

test('a bulk-rostered off day is classified Off Day, not Absent', function () {
    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee();
    clearDay($employee, $date);

    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);

    // Exactly what bulkAssign() produces: a duty for every calendar day,
    // status 'Scheduled', with a shift and scheduled times.
    $shift = RosterShift::factory()->create(['start_time' => '09:00:00', 'end_time' => '17:00:00']);
    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'shift_id' => $shift->id,
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Scheduled',
    ]);

    // Guard the guard: if this is 0 the reconciler cannot see the duty and the
    // assertion below would pass for the wrong reason (defect 2).
    expect(RosterDuty::where('employee_id', $employee->employee_id)
        ->whereDate('roster_date', $date)->count())->toBe(1);

    expect(classify($employee, $date))->toBe('Off Day');
});

test('the reconciler can actually see roster duties on every driver', function () {
    // Pins defect 2 directly: `whereBetween` on a `date` cast matches nothing on
    // SQLite, which made all roster duties invisible to the reconciler.
    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-ROSTER-VISIBLE');
    clearDay($employee, $date);

    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'status' => 'Off',
    ]);

    $stored = DB::table('roster_duties')
        ->where('employee_id', $employee->employee_id)
        ->value('roster_date');

    // Whatever the driver stores, a date-cast range query must find it.
    expect(RosterDuty::where('employee_id', $employee->employee_id)
        ->whereDate('roster_date', '>=', $date)
        ->whereDate('roster_date', '<=', $date)
        ->count())->toBe(1, 'raw stored value was '.var_export($stored, true));

    // And an explicit roster Off must be honoured even with no fallback set.
    expect(classify($employee, $date))->toBe('Off Day');
});

// ── Surrounding behaviour that must not change ────────────────────────────────

test('a roster duty marked Off is an off day even with no fallback configured', function () {
    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-ROSTER-OFF');
    clearDay($employee, $date);

    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'status' => 'Off',
    ]);

    expect(classify($employee, $date))->toBe('Off Day');
});

test('a rostered working day with no punches is still Absent', function () {
    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-ABSENT');
    clearDay($employee, $date);

    // No fallback off day at all — the roster is the only source of truth.
    $shift = RosterShift::factory()->create();
    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'shift_id' => $shift->id,
        'status' => 'Scheduled',
    ]);

    expect(classify($employee, $date))->toBe('Absent');
});

test('the fallback off day only applies on its own weekday', function () {
    $friday = offDayTestFriday();
    $employee = makeOffDayEmployee('EMP-WEEKDAY');

    // Thursday: not the fallback day.
    $thursday = $friday->copy()->subDay()->toDateString();
    clearDay($employee, $thursday);
    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);

    expect(classify($employee, $thursday))->toBe('Absent');

    // Friday: the fallback day.
    $fridayDate = $friday->toDateString();
    clearDay($employee, $fridayDate);
    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);

    expect(classify($employee, $fridayDate))->toBe('Off Day');
});

test('Sunday (weekday 0) is not mistaken for "no fallback configured"', function () {
    // day_of_week 0 is falsy — a loose check would treat it as unset.
    $sunday = offDayTestFriday()->subDays(5);
    while ($sunday->dayOfWeek !== 0) {
        $sunday->subDay();
    }
    $date = $sunday->toDateString();
    $employee = makeOffDayEmployee('EMP-SUNDAY');
    clearDay($employee, $date);

    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 0]);

    expect(classify($employee, $date))->toBe('Off Day');
});

test('strict roster authority can be restored by configuration', function () {
    config()->set('hrm.attendance.roster_overrides_fallback_off_day', true);

    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-STRICT');
    clearDay($employee, $date);

    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);
    $shift = RosterShift::factory()->create();
    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'shift_id' => $shift->id,
        'status' => 'Scheduled',
    ]);

    // With strict authority the roster wins, so a no-show is a real absence.
    expect(classify($employee, $date))->toBe('Absent');
});

test('an explicit roster Off still wins under strict roster authority', function () {
    config()->set('hrm.attendance.roster_overrides_fallback_off_day', true);

    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-STRICT-OFF');
    clearDay($employee, $date);

    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'status' => 'Off',
    ]);

    expect(classify($employee, $date))->toBe('Off Day');
});

// ── Resolver unit behaviour ───────────────────────────────────────────────────

test('the resolver treats an explicit roster Off as authoritative', function () {
    $resolver = app(OffDayResolver::class);
    $duty = new RosterDuty(['status' => 'Off']);

    // Even on a weekday that is NOT the fallback day.
    expect($resolver->isOffDay($duty, 5, '2026-10-21'))->toBeTrue();   // a Wednesday
    expect($resolver->isOffDay($duty, null, '2026-10-21'))->toBeTrue();
});

test('the resolver returns false when no fallback is configured and the roster is not Off', function () {
    $resolver = app(OffDayResolver::class);

    expect($resolver->isOffDay(null, null, '2026-10-23'))->toBeFalse();
    expect($resolver->isOffDay(new RosterDuty(['status' => 'Scheduled']), null, '2026-10-23'))->toBeFalse();
});

test('the resolver matches the fallback weekday only', function () {
    $resolver = app(OffDayResolver::class);

    expect($resolver->isOffDay(null, 5, '2026-10-23'))->toBeTrue();   // Friday
    expect($resolver->isOffDay(null, 5, '2026-10-22'))->toBeFalse();  // Thursday
    expect($resolver->isOffDay(null, 0, '2026-10-18'))->toBeTrue();   // Sunday, weekday 0
});

test('the resolver accepts a Carbon instance as well as a date string', function () {
    $resolver = app(OffDayResolver::class);

    expect($resolver->isOffDay(null, 5, Carbon::parse('2026-10-23')))->toBeTrue();
    expect($resolver->isOffDay(null, 5, Carbon::parse('2026-10-23')->startOfDay()))->toBeTrue();
});

// ── Worked hours on an off day must never be lost ─────────────────────────────

test('punching in on a bulk-rostered off day yields Extra Duty, not Off Day', function () {
    // The off-day rule only decides how an EMPTY day is labelled. If the
    // employee actually worked, the punch pipeline must classify the day and
    // the hours must survive as premium (Extra Duty) work.
    $friday = offDayTestFriday();
    $date = $friday->toDateString();
    $employee = makeOffDayEmployee('EMP-OFFDAY-WORKED');
    clearDay($employee, $date);

    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);

    $shift = RosterShift::factory()->create(['start_time' => '09:00:00', 'end_time' => '17:00:00']);
    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $date,
        'shift_id' => $shift->id,
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Scheduled',
    ]);

    $pair = AttendancePair::create([
        'employee_id' => $employee->employee_id,
        'duty_date' => $date,
        'shift_id' => $shift->id,
        'punch_in' => "{$date} 09:02:00",
        'punch_out' => "{$date} 17:05:00",
        'worked_hours' => 8.05,
        // duty_type matters: a non-Primary pair takes the overtime branch of
        // upsertForPair(), which forces Extra Duty regardless of the off-day rule.
        'duty_type' => 'Primary',
    ]);

    $daily = app(AttendanceDailyRepository::class)->upsertForPair($pair);

    expect($daily->status)->toBe('Extra Duty');
    expect((float) $daily->grand_total_hours)->toBeGreaterThan(0);

    // And the reconciler must not downgrade a row that already carries punches.
    expect(classify($employee, $date))->toBe('Extra Duty');
});

test('punching in on a normal rostered working day is still Present', function () {
    // Mirror of the test above: the off-day rule must not leak into a regular
    // working day and turn it into premium Extra Duty.
    $friday = offDayTestFriday();
    $thursday = $friday->copy()->subDay()->toDateString();
    $employee = makeOffDayEmployee('EMP-WORKDAY-WORKED');
    clearDay($employee, $thursday);

    EmployeeFallbackOffDay::create(['employee_id' => $employee->employee_id, 'day_of_week' => 5]);

    $shift = RosterShift::factory()->create(['start_time' => '09:00:00', 'end_time' => '17:00:00']);
    RosterDuty::create([
        'employee_id' => $employee->employee_id,
        'roster_date' => $thursday,
        'shift_id' => $shift->id,
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Scheduled',
    ]);

    $pair = AttendancePair::create([
        'employee_id' => $employee->employee_id,
        'duty_date' => $thursday,
        'shift_id' => $shift->id,
        'punch_in' => "{$thursday} 08:55:00",
        'punch_out' => "{$thursday} 17:10:00",
        'worked_hours' => 8.25,
        'duty_type' => 'Primary',
    ]);

    $daily = app(AttendanceDailyRepository::class)->upsertForPair($pair);

    expect($daily->status)->toBe('Present');
});

afterEach(function () {
    foreach (['EMP-OFFDAY', 'EMP-ROSTER-VISIBLE', 'EMP-ROSTER-OFF', 'EMP-ABSENT', 'EMP-WEEKDAY', 'EMP-SUNDAY', 'EMP-STRICT', 'EMP-STRICT-OFF', 'EMP-OFFDAY-WORKED', 'EMP-WORKDAY-WORKED'] as $id) {
        $employee = Employee::where('employee_id', $id)->first();
        if ($employee) {
            clearDay($employee, offDayTestFriday()->toDateString());
        }
        EmployeeFallbackOffDay::where('employee_id', $id)->delete();
        RosterDuty::where('employee_id', $id)->delete();
        AttendanceDaily::where('employee_id', $id)->delete();
    }
});

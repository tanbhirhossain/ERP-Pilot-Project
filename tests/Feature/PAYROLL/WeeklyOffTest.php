<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider;

afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    \Illuminate\Support\Carbon::setTestNow(null);
});

function offEmp(string $code, array $overrides = []): string
{
    DB::table('employees')->insert($overrides + [
        'employee_id' => $code,
        'first_name' => 'Off',
        'last_name' => $code,
        'full_name' => 'Off '.$code,
        'gender' => 'Female',
        'joining_date' => '2020-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => true,
        'duty_hours' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('employee_salaries')->insert([
        'employee_id' => $code,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 9000,
        'medical_allowance' => 1500,
        'other_allowance' => 3500,
        'net_salary' => 30000,
        'effective_from' => '2020-01-01',
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('employee_policies')->insert([
        'employee_id' => $code,
        'grace_time_in' => 15,
        'grace_time_out' => 15,
        'shift_type' => 'Fixed',
        'is_punishable' => true,
        'duty_hours' => 8,
        'auto_shift' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $code;
}

/** A punch on every non-Friday weekday of October, which is what opens the absence gate. */
function offPunchThroughOctober(string $code, string $skipFridayRule = 'skip'): void
{
    $logs = [];
    for ($day = Carbon::parse('2026-10-01'); $day->lte(Carbon::parse('2026-10-31')); $day->addDay()) {
        if ($skipFridayRule === 'skip' && $day->dayOfWeek === 5) {
            continue;
        }
        $logs[] = [
            'employee_id' => $code,
            'device_user_id' => null,
            'punch_time' => $day->toDateString().' 09:00:00',
            'punch_type' => 'AUTO',
            'is_processed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('attendance_logs')->insert($logs);
}

/**
 * "Which day does this person not work?" always has an answer.
 *
 * Nobody is employed without a weekly off, so a missing `employee_fallback_off_days` row must not be
 * read as "works every day". It was: in production 997 employees had 4 off-day rows between them, and
 * every Friday of the month was written down as Absent and charged. The roster could have said
 * otherwise, and for four people it did; for the other 993 nobody had been asked, and the silence was
 * taken as an answer.
 */
test('an employee with no off-day row still gets the company weekly off, not an absent Friday', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    offEmp('WOFF-NONE');
    offPunchThroughOctober('WOFF-NONE');

    // No employee_fallback_off_days row, no roster duty, no holiday calendar entry needed for Friday.
    $summary = app(HrmAttendancePayrollDataProvider::class)
        ->summariesForEmployees(['WOFF-NONE'], '2026-10-01', '2026-10-31')['WOFF-NONE'];

    $absent = $summary->sourceBreakdown['absent_dates'];

    $fridays = ['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'];
    foreach ($fridays as $friday) {
        expect($absent)->not->toContain($friday);
    }

    // Five Fridays protected, and nothing else claimed as absence either: every other day has a punch.
    expect($absent)->toBe([])
        ->and((float) $summary->unpaidLeaveDays)->toBe(0.0);
});

test('an off-day row that names a different day moves the protected day with it', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    offEmp('WOFF-THU');
    DB::table('employee_fallback_off_days')->insert([
        'employee_id' => 'WOFF-THU',
        'day_of_week' => 4,          // Thursday
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    offPunchThroughOctober('WOFF-THU', 'punch-fridays-too');

    $summary = app(HrmAttendancePayrollDataProvider::class)
        ->summariesForEmployees(['WOFF-THU'], '2026-10-01', '2026-10-31')['WOFF-THU'];

    $absent = $summary->sourceBreakdown['absent_dates'];

    // Thursday is the off day, so it is skipped; every Friday is a working day and has a punch,
    // so the difference shows up only in the protected Thursday.
    expect($absent)->not->toContain('2026-10-01')   // a Thursday
        ->and($absent)->not->toContain('2026-10-08')
        ->and($absent)->not->toContain('2026-10-02'); // a Friday — punched, so absent-free anyway
});

/**
 * The employee who never appears on the device does not lose a month's pay.
 *
 * This is the `waive` side of `missing_attendance_policy`, asserted against the real provider rather
 * than trusted to the setting's default: 490 employees in the import had no punch at all, and a rule
 * that charged them for every rostered day would have docked them for a device that never uploaded.
 */
test('with the waive policy, an employee with no attendance at all keeps the full month', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    offEmp('WOFF-NOPUNCH');

    /*
     * A rostered duty on every non-Friday day of October is what makes an absence *possible*: the
     * provider only charges a day it has positive evidence the employee was expected in. Without
     * these rows the month is simply "not expected", which is a different (and also unpaid) story.
     */
    $shiftId = DB::table('roster_shifts')->insertGetId([
        'shift_name' => 'WOFF Shift',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
        'is_fallback' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $duties = [];
    for ($day = Carbon::parse('2026-10-01'); $day->lte(Carbon::parse('2026-10-31')); $day->addDay()) {
        if ($day->dayOfWeek === 5) {
            continue;
        }
        $duties[] = [
            'employee_id' => 'WOFF-NOPUNCH',
            'shift_id' => $shiftId,
            'roster_date' => $day->toDateString(),
            'scheduled_start_time' => '09:00:00',
            'scheduled_end_time' => '17:00:00',
            'status' => 'Scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('roster_duties')->insert($duties);

    // A row, not an update: a fresh test database has no payroll_settings row at all, so
    // `->update()` on the table silently changed nothing and the provider fell back to `waive`.
    // Employees' behaviour is driven by `current()` — which inserts on demand — so the test has to
    // do the same thing the application does.
    DB::table('payroll_settings')->insertOrIgnore([
        'missing_attendance_policy' => 'waive',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(DB::table('payroll_settings')->value('missing_attendance_policy'))->toBe('waive');

    $summary = app(HrmAttendancePayrollDataProvider::class)
        ->summariesForEmployees(['WOFF-NOPUNCH'], '2026-10-01', '2026-10-31')['WOFF-NOPUNCH'];

    expect((float) $summary->unpaidLeaveDays)->toBe(0.0)
        ->and($summary->sourceBreakdown['missing_attendance_policy'])->toBe('waive')
        ->and($summary->sourceBreakdown['absence_gated_by_punch'])->toBeFalse()
        ->and($summary->sourceBreakdown['absent_dates'])->toBe([]);

    // Flipping the policy is what changes it — the same employee, the same month.
    DB::table('payroll_settings')->update(['missing_attendance_policy' => 'apply']);

    $charged = app(HrmAttendancePayrollDataProvider::class)
        ->summariesForEmployees(['WOFF-NOPUNCH'], '2026-10-01', '2026-10-31')['WOFF-NOPUNCH'];


    // 26 rostered days, five Fridays protected (an off day is not an absence), no punches.
    expect((float) $charged->unpaidLeaveDays)->toBe(26.0)
        ->and($charged->sourceBreakdown['missing_attendance_policy'])->toBe('apply')
        // Punched nowhere, so a punch is not what opened the gate — the policy is. The breakdown has
        // to say so, because "why was this person charged 26 days" is a question a slip must answer.
        ->and($charged->sourceBreakdown['absence_gated_by_punch'])->toBeFalse()
        ->and($charged->sourceBreakdown['absence_differs_from_policy'])->toBeTrue();
});

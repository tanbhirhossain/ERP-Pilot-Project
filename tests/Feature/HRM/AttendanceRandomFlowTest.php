<?php

/**
 * Random Punches Stress Test — Log → Pair → Daily Attendance
 * Covers: many random IN/OUT, day + night, overlapping, debounce, buffer edges
 * Ensures no cross-day inversion (16h+ bug) after auto-shift engine fix
 */

use Carbon\Carbon;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
    mt_srand(12345); // deterministic random for CI
});

function randomFlowSetup(array $policy = [], array $empOverrides = []): array
{
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp = Employee::factory()->create(array_merge([
        'department_id' => $dept->id, 'designation_id' => $desig->id,
        'allow_overtime' => true, 'duty_hours' => 8,
    ], $empOverrides));
    EmployeePolicy::factory()->create(array_merge([
        'employee_id' => $emp->employee_id, 'shift_type' => 'Fixed',
        'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true,
        'duty_hours' => 8, 'auto_shift' => true, 'buffer_minutes' => 90, 'allow_fallback' => true,
    ], $policy));

    return compact('emp', 'dept', 'desig');
}

function createAllShifts(): array
{
    // 6h, 7h, 8h day, 8h night — same as FullFlowTest for consistency
    $shift6 = RosterShift::factory()->create([
        'shift_name' => 'Morning 6H', 'start_time' => '06:00:00', 'end_time' => '12:00:00',
        'punch_in_window_start' => '05:30:00', 'punch_in_window_end' => '07:00:00',
        'crosses_midnight' => false, 'max_duration_hours' => 24, 'buffer_before' => 60, 'buffer_after' => 90,
    ]);
    $shift7 = RosterShift::factory()->create([
        'shift_name' => 'Afternoon 7H', 'start_time' => '14:00:00', 'end_time' => '21:00:00',
        'punch_in_window_start' => '13:30:00', 'punch_in_window_end' => '15:00:00',
        'crosses_midnight' => false, 'max_duration_hours' => 24, 'buffer_before' => 60, 'buffer_after' => 90,
    ]);
    $shift8Day = RosterShift::factory()->create([
        'shift_name' => 'Day 8H', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00', 'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false, 'max_duration_hours' => 24, 'buffer_before' => 60, 'buffer_after' => 90,
    ]);
    $shift8Night = RosterShift::factory()->create([
        'shift_name' => 'Night 8H', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'punch_in_window_start' => '21:00:00', 'punch_in_window_end' => '23:00:00',
        'crosses_midnight' => true, 'max_duration_hours' => 24, 'buffer_before' => 60, 'buffer_after' => 90,
    ]);

    return compact('shift6', 'shift7', 'shift8Day', 'shift8Night');
}

function randomMinutes(int $min, int $max): int
{
    return mt_rand($min, $max);
}

function punchWithJitter(string $date, string $baseTime, int $jitterMin = 30): string
{
    [$h, $m] = array_map('intval', explode(':', $baseTime));
    $j = randomMinutes(-$jitterMin, $jitterMin);
    $c = Carbon::parse($date)->setTime($h, $m, 0)->addMinutes($j);

    return $c->toDateTimeString();
}

function processLogs(array $punchTimes, string $empId, string $deviceId = 'RND'): void
{
    $proc = app(AttendanceProcessingService::class);
    foreach ($punchTimes as $t) {
        $log = AttendanceLog::factory()->create([
            'employee_id' => $empId, 'device_user_id' => $deviceId,
            'punch_time' => $t, 'is_processed' => false,
        ]);
        $proc->processSingle($log);
    }
}

function assertNoCrossDayInversion(string $empId): void
{
    $pairs = AttendancePair::where('employee_id', $empId)->get();
    foreach ($pairs as $p) {
        if ($p->punch_out) {
            $hours = (float) $p->worked_hours;
            // Fixed shift should never be >12h (16h+ bug)
            expect($hours)->toBeLessThan(12, "Pair {$p->id} has {$hours}h >12h — inversion bug (punch_in {$p->punch_in} → {$p->punch_out})");
            // Day shift: punch_in date should equal duty_date (night allowed to have punch_out next day)
            $shift = $p->shift;
            if ($shift && ! $shift->crosses_midnight) {
                $inDate = Carbon::parse($p->punch_in)->toDateString();
                expect($p->duty_date->toDateString())->toBe($inDate, "Day shift pair {$p->id} duty_date {$p->duty_date} != punch_in date $inDate");
            }
        }
    }
}

// -----------------------------------------------------------------------------

test('random day shift 10 days — no cross-day inversion', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8]);
    $shifts = createAllShifts();
    $day = $shifts['shift8Day'];
    // Roster for 10 consecutive days
    for ($d = 1; $d <= 10; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        RosterDuty::factory()->create([
            'employee_id' => $emp->employee_id, 'shift_id' => $day->id,
            'roster_date' => $date, 'scheduled_start_time' => '09:00:00', 'scheduled_end_time' => '17:00:00',
        ]);
    }
    $punches = [];
    for ($d = 1; $d <= 10; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        // Random IN/OUT within buffer
        $punches[] = punchWithJitter($date, '09:00', 25);
        $punches[] = punchWithJitter($date, '17:00', 35);
        // Randomly add extra break punches 2-3 days (e.g., lunch)
        if (mt_rand(0, 10) < 3) {
            // Will become Overtime pair — still should not invert
            $punches[] = punchWithJitter($date, '18:30', 20);
            $punches[] = punchWithJitter($date, '20:00', 20);
        }
    }
    // Shuffle to simulate out-of-order device sync? But our engine orders by punch_time, so shuffle should still be handled via sort.
    // We keep chronological for this test — shuffle would test reordering robustness, but we test chronological first.
    sort($punches);
    processLogs($punches, $emp->employee_id, 'RND-DAY');

    // Assertions
    $pairs = AttendancePair::where('employee_id', $emp->employee_id)->get();
    // At least 10 primary pairs (one per day) — may be more due to overtime
    expect($pairs->where('duty_type', 'Primary')->count())->toBeGreaterThanOrEqual(10);
    assertNoCrossDayInversion($emp->employee_id);

    $dailies = AttendanceDaily::where('employee_id', $emp->employee_id)->get();
    expect($dailies->count())->toBeGreaterThanOrEqual(10);
    foreach ($dailies as $d) {
        expect((float) $d->grand_total_hours)->toBeGreaterThan(5)->toBeLessThan(13);
        expect($d->status)->toBeIn(['Present', 'Late', 'Early Out', 'Late & Early Out']);
    }
});

test('random night shift 10 days crossing midnight — duty_date stays IN date', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8]);
    $shifts = createAllShifts();
    $night = $shifts['shift8Night'];
    for ($d = 1; $d <= 10; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        RosterDuty::factory()->create([
            'employee_id' => $emp->employee_id, 'shift_id' => $night->id,
            'roster_date' => $date, 'scheduled_start_time' => '22:00:00', 'scheduled_end_time' => '06:00:00',
        ]);
    }
    $punches = [];
    for ($d = 1; $d <= 10; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        $nextDate = Carbon::parse($date)->addDay()->toDateString();
        $punches[] = punchWithJitter($date, '22:05', 30);      // IN night
        $punches[] = punchWithJitter($nextDate, '06:05', 40);  // OUT next morning (crosses midnight)
    }
    sort($punches);
    processLogs($punches, $emp->employee_id, 'RND-NIGHT');

    $pairs = AttendancePair::where('employee_id', $emp->employee_id)->get();
    expect($pairs->count())->toBe(10);
    foreach ($pairs as $p) {
        // IN date == duty_date, OUT is next day
        $inDate = Carbon::parse($p->punch_in)->toDateString();
        expect($p->duty_date->toDateString())->toBe($inDate);
        $outDate = Carbon::parse($p->punch_out)->toDateString();
        // OUT should be next day
        expect($outDate)->not->toBe($inDate);
        expect((float) $p->worked_hours)->toBeGreaterThan(7)->toBeLessThan(9.5);
    }
    assertNoCrossDayInversion($emp->employee_id);
});

test('mixed day and night for same employee over 20 days — auto detects correctly', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8, 'auto_shift' => true]);
    $shifts = createAllShifts();
    $day = $shifts['shift8Day'];
    $night = $shifts['shift8Night'];
    // Alternate roster: odd days Day, even days Night
    for ($d = 1; $d <= 20; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        $shift = ($d % 2 === 1) ? $day : $night;
        RosterDuty::factory()->create([
            'employee_id' => $emp->employee_id, 'shift_id' => $shift->id,
            'roster_date' => $date,
        ]);
    }
    $punches = [];
    for ($d = 1; $d <= 20; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        if ($d % 2 === 1) {
            // Day
            $punches[] = punchWithJitter($date, '09:10', 20);
            $punches[] = punchWithJitter($date, '17:20', 30);
        } else {
            $next = Carbon::parse($date)->addDay()->toDateString();
            $punches[] = punchWithJitter($date, '22:10', 20);
            $punches[] = punchWithJitter($next, '06:15', 25);
        }
    }
    sort($punches);
    processLogs($punches, $emp->employee_id, 'RND-MIX');

    $pairs = AttendancePair::where('employee_id', $emp->employee_id)->orderBy('duty_date')->get();
    expect($pairs->count())->toBe(20);
    assertNoCrossDayInversion($emp->employee_id);

    // Verify day pairs have Day shift, night pairs have Night shift
    foreach ($pairs as $p) {
        $d = (int) Carbon::parse($p->duty_date)->format('d');
        if ($d % 2 === 1) {
            expect($p->shift_id)->toBe($day->id, "Odd day $d should be Day shift");
        } else {
            expect($p->shift_id)->toBe($night->id, "Even day $d should be Night shift");
        }
    }
});

test('too many random punches stress — 50 punches day shift, no 16h+ artifact', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8]);
    $shifts = createAllShifts();
    $day = $shifts['shift8Day'];
    // 15 days roster
    for ($d = 1; $d <= 15; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $day->id, 'roster_date' => $date]);
    }
    $punches = [];
    // Generate 50 random punches across 15 days — random gaps, some days 4 punches, some 2
    for ($i = 0; $i < 50; $i++) {
        $dayNum = mt_rand(1, 15);
        $date = sprintf('2026-10-%02d', $dayNum);
        // Random time between 08:00-20:00
        $h = mt_rand(8, 19);
        $m = mt_rand(0, 59);
        $punches[] = sprintf('%s %02d:%02d:00', $date, $h, $m);
    }
    // Intentionally shuffle to simulate device out-of-order, then sort by punch_time (engine does this)
    // But we process in random order to test debounce + invalid_time_order handling
    shuffle($punches);
    // Process via processPending which orders by punch_time internally — but processLogs processes in given order
    // So we test both: first sort then process
    sort($punches);
    processLogs($punches, $emp->employee_id, 'RND-STRESS');

    // Even with 50 random, no pair should exceed 12h (Fixed)
    assertNoCrossDayInversion($emp->employee_id);
    $maxHours = AttendancePair::where('employee_id', $emp->employee_id)->max('worked_hours');
    expect((float) $maxHours)->toBeLessThan(12);

    // Dailies should exist for days with at least one closed pair
    $dailies = AttendanceDaily::where('employee_id', $emp->employee_id)->get();
    expect($dailies->count())->toBeGreaterThan(0);
});

test('multiple employees random concurrent — isolation', function () {
    $shifts = createAllShifts();
    $day = $shifts['shift8Day'];
    $night = $shifts['shift8Night'];
    $emps = [];
    for ($e = 0; $e < 3; $e++) {
        ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8]);
        $emps[] = $emp;
        // Each employee has different roster pattern
        for ($d = 1; $d <= 5; $d++) {
            $date = sprintf('2026-10-%02d', $d);
            $shift = ($e === 1) ? $night : $day; // emp 1 is night, others day
            RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'roster_date' => $date]);
        }
    }
    $allPunches = [];
    foreach ($emps as $idx => $emp) {
        $isNight = ($idx === 1);
        for ($d = 1; $d <= 5; $d++) {
            $date = sprintf('2026-10-%02d', $d);
            if ($isNight) {
                $next = Carbon::parse($date)->addDay()->toDateString();
                $allPunches[] = ['emp' => $emp->employee_id, 'time' => punchWithJitter($date, '22:00', 20)];
                $allPunches[] = ['emp' => $emp->employee_id, 'time' => punchWithJitter($next, '06:00', 20)];
            } else {
                $allPunches[] = ['emp' => $emp->employee_id, 'time' => punchWithJitter($date, '09:00', 20)];
                $allPunches[] = ['emp' => $emp->employee_id, 'time' => punchWithJitter($date, '17:00', 20)];
            }
        }
    }
    // Global sort by time to simulate interleaved device logs
    usort($allPunches, fn ($a, $b) => strcmp($a['time'], $b['time']));
    $proc = app(AttendanceProcessingService::class);
    foreach ($allPunches as $p) {
        $log = AttendanceLog::factory()->create([
            'employee_id' => $p['emp'], 'device_user_id' => 'MULTI',
            'punch_time' => $p['time'], 'is_processed' => false,
        ]);
        $proc->processSingle($log);
    }

    foreach ($emps as $emp) {
        assertNoCrossDayInversion($emp->employee_id);
        $cnt = AttendancePair::where('employee_id', $emp->employee_id)->count();
        expect($cnt)->toBe(5);
    }
});

test('buffer edge — punch at exact buffer limit still matches', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8, 'buffer_minutes' => 90]);
    $shifts = createAllShifts();
    $day = $shifts['shift8Day']; // 09:00-17:00, buffer 60 before / 90 after per shift
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $day->id, 'roster_date' => '2026-10-10']);

    // IN at 08:00 exactly buffer_before (60m before 09:00) — should match
    processLogs(['2026-10-10 08:00:00', '2026-10-10 17:00:00'], $emp->employee_id, 'BUF');
    $pair = AttendancePair::where('employee_id', $emp->employee_id)->first();
    expect($pair->shift_id)->toBe($day->id);
    expect((float) $pair->worked_hours)->toBe(9.0);

    // Clean for next
    AttendancePair::truncate();
    AttendanceDaily::truncate();
    AttendanceLog::truncate();

    // IN at 18:30 exactly buffer_after (90m after 17:00) — should still match as OUT grace
    // Create new day
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $day->id, 'roster_date' => '2026-10-11']);
    processLogs(['2026-10-11 09:00:00', '2026-10-11 18:30:00'], $emp->employee_id, 'BUF2');
    $pair2 = AttendancePair::where('employee_id', $emp->employee_id)->first();
    expect($pair2->shift_id)->toBe($day->id);
    expect((float) $pair2->worked_hours)->toBe(9.5);
});

test('batch processPending with random punches via pipeline ordering', function () {
    ['emp' => $emp] = randomFlowSetup(['duty_hours' => 8]);
    $shifts = createAllShifts();
    $day = $shifts['shift8Day'];
    for ($d = 10; $d <= 15; $d++) {
        RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $day->id, 'roster_date' => sprintf('2026-10-%02d', $d)]);
    }
    $punches = [];
    for ($d = 10; $d <= 15; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        $punches[] = punchWithJitter($date, '09:05', 10);
        $punches[] = punchWithJitter($date, '17:05', 10);
    }
    shuffle($punches);
    // Insert as unprocessed logs without immediate processing
    foreach ($punches as $t) {
        AttendanceLog::factory()->create([
            'employee_id' => $emp->employee_id, 'device_user_id' => 'BATCH',
            'punch_time' => $t, 'is_processed' => false,
        ]);
    }
    $proc = app(AttendanceProcessingService::class);
    $res = $proc->processPending(100);
    expect($res['processed'])->toBe(12);
    expect($res['closed_pairs'])->toBe(6);
    assertNoCrossDayInversion($emp->employee_id);
    expect(AttendanceDaily::where('employee_id', $emp->employee_id)->count())->toBe(6);
});

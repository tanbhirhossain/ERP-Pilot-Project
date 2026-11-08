<?php

/**
 * Full Flow Attendance E2E: Log -> Pair -> Daily Report
 * Covers:
 *  - Day shift (09-17) via roster
 *  - Night shift crossing midnight (22-06) duty_date logic
 *  - Auto-shift detection by duty_hours 6/7/8 when roster missing
 *  - Overtime auto-generation when worked > duty_hours
 *  - FIFO pairing across consecutive days
 *  - Debounce & late/early status
 */

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

function fullFlowSetup(array $policyOverrides = [], array $employeeOverrides = []): array
{
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp = Employee::factory()->create(array_merge([
        'department_id' => $dept->id,
        'designation_id' => $desig->id,
        'allow_overtime' => true,
        'duty_hours' => 8,
    ], $employeeOverrides));
    // Ensure policy exists
    $policy = EmployeePolicy::factory()->create(array_merge([
        'employee_id' => $emp->employee_id,
        'shift_type' => 'Fixed',
        'grace_time_in' => 15,
        'grace_time_out' => 15,
        'is_punishable' => true,
        'duty_hours' => 8,
        'auto_shift' => true,
    ], $policyOverrides));

    return compact('emp', 'dept', 'desig', 'policy');
}

function createShiftsForAutoDetect(): array
{
    // Clean slate - ensure no stray shifts from other tests (each test has fresh DB)
    // Create 3 shifts with distinct durations 6h, 7h, 8h
    $shift6 = RosterShift::factory()->create([
        'shift_name' => 'Morning 6H',
        'start_time' => '06:00:00',
        'end_time' => '12:00:00',
        'punch_in_window_start' => '05:30:00',
        'punch_in_window_end' => '07:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
    $shift7 = RosterShift::factory()->create([
        'shift_name' => 'Afternoon 7H',
        'start_time' => '14:00:00',
        'end_time' => '21:00:00',
        'punch_in_window_start' => '13:30:00',
        'punch_in_window_end' => '15:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
    $shift8Day = RosterShift::factory()->create([
        'shift_name' => 'Day 8H',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
    $shift8Night = RosterShift::factory()->create([
        'shift_name' => 'Night 8H',
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'punch_in_window_start' => '21:00:00',
        'punch_in_window_end' => '23:00:00',
        'crosses_midnight' => true,
        'max_duration_hours' => 24,
    ]);

    return compact('shift6', 'shift7', 'shift8Day', 'shift8Night');
}

// -----------------------------------------------------------------------------
// Helpers for processing
function processPunch(string $employeeId, string $deviceUserId, string $punchTime): array
{
    $log = AttendanceLog::factory()->create([
        'employee_id' => $employeeId,
        'device_user_id' => $deviceUserId,
        'punch_time' => $punchTime,
        'is_processed' => false,
    ]);
    $processor = app(AttendanceProcessingService::class);

    return $processor->processSingle($log);
}
function processPunches(string $employeeId, string $deviceUserId, array $times): array
{
    $processor = app(AttendanceProcessingService::class);
    $results = [];
    foreach ($times as $t) {
        $log = AttendanceLog::factory()->create([
            'employee_id' => $employeeId,
            'device_user_id' => $deviceUserId,
            'punch_time' => $t,
            'is_processed' => false,
        ]);
        $results[] = $processor->processSingle($log);
    }

    // also test processPending in batch mode works
    return $results;
}

// -----------------------------------------------------------------------------
// DAY SHIFT via ROSTER
test('full flow day shift 8h via roster - IN at 09:05 OUT at 17:10 => Present no overtime (within duty)', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8, 'auto_shift' => true]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];

    // Roster authoritative for today
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id,
        'shift_id' => $dayShift->id,
        'roster_date' => '2026-10-26',
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Scheduled',
    ]);

    $resIn = processPunch($emp->employee_id, '1001', '2026-10-26 09:05:00');
    expect($resIn['status'])->toBe('processed')->and($resIn['action'])->toBe('created');
    expect(AttendancePair::count())->toBe(1);
    $pair = AttendancePair::first();
    expect($pair->shift_id)->toBe($dayShift->id)->and($pair->duty_date->toDateString())->toBe('2026-10-26');
    expect($pair->duty_type)->toBe('Primary');

    // Daily skeleton after IN
    $dailyAfterIn = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-26')->first();
    expect($dailyAfterIn)->not->toBeNull();
    expect($dailyAfterIn->status)->toBe('Missing Out');
    expect($dailyAfterIn->primary_punch_in)->not->toBeNull();

    $resOut = processPunch($emp->employee_id, '1001', '2026-10-26 17:10:00');
    expect($resOut['status'])->toBe('processed')->and($resOut['action'])->toBe('closed');

    $pair->refresh();
    expect((float) $pair->worked_hours)->toBeGreaterThan(8.0)->toBeLessThan(8.3); // 8.08

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-26')->first();
    expect($daily)->not->toBeNull();
    expect((float) $daily->primary_worked_hours)->toBeGreaterThan(8.0);
    expect((float) $daily->grand_total_hours)->toBeGreaterThan(8.0);
    expect($daily->status)->toBe('Present'); // 09:05 within 15m grace vs 09:00
    expect($daily->primary_shift_id)->toBe($dayShift->id);

    // Overtime auto-generation: worked 8.08h vs duty 8h => excess 0.08 < 0.25 so no overtime
    expect(RosterOvertime::where('employee_id', $emp->employee_id)->whereDate('overtime_date', '2026-10-26')->exists())->toBeFalse();
});

test('full flow day shift with overtime - 09:00 to 19:00 (10h) => auto overtime 2h pending', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id,
        'shift_id' => $dayShift->id,
        'roster_date' => '2026-10-27',
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
    ]);

    processPunch($emp->employee_id, '1001', '2026-10-27 09:00:00');
    processPunch($emp->employee_id, '1001', '2026-10-27 19:00:00');

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-27')->first();
    expect((float) $daily->grand_total_hours)->toBe(10.0);

    $ot = RosterOvertime::where('employee_id', $emp->employee_id)->whereDate('overtime_date', '2026-10-27')->first();
    expect($ot)->not->toBeNull();
    expect($ot->overtime_type)->toBe('Overtime');
    expect((float) $ot->hours)->toBe(2.0);
    expect($ot->status)->toBe('Pending');
    expect($ot->reason)->toContain('Auto-generated');
});

// -----------------------------------------------------------------------------
// NIGHT SHIFT crossing midnight
test('full flow night shift 22-06 crossing midnight - IN 22:05 OUT 06:10 next day => duty_date stays 26th', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    $night = $shifts['shift8Night'];

    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id,
        'shift_id' => $night->id,
        'roster_date' => '2026-10-26',
        'scheduled_start_time' => '22:00:00',
        'scheduled_end_time' => '06:00:00',
    ]);

    $r1 = processPunch($emp->employee_id, '2001', '2026-10-26 22:05:00');
    expect($r1['action'])->toBe('created');
    $p1 = AttendancePair::first();
    expect($p1->shift_id)->toBe($night->id);
    expect($p1->duty_date->toDateString())->toBe('2026-10-26');

    $r2 = processPunch($emp->employee_id, '2001', '2026-10-27 06:10:00');
    expect($r2['action'])->toBe('closed');
    $p1->refresh();
    expect($p1->duty_date->toDateString())->toBe('2026-10-26'); // not 27th
    expect((float) $p1->worked_hours)->toBeGreaterThan(8.0)->toBeLessThan(8.5); // 8h05

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-26')->first();
    expect($daily)->not->toBeNull();
    expect($daily->primary_punch_in->toDateTimeString())->toBe('2026-10-26 22:05:00');
    expect($daily->primary_punch_out->toDateTimeString())->toBe('2026-10-27 06:10:00');
    expect((float) $daily->primary_worked_hours)->toBeGreaterThan(8);
    expect($daily->status)->toBe('Present');
});

test('night shift early punch belongs to previous duty - 02:00 punch maps to prior day roster', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    $night = $shifts['shift8Night'];
    // Roster on 26th is night
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id,
        'shift_id' => $night->id,
        'roster_date' => '2026-10-26',
    ]);

    // Directly test resolveDutyDate helper: 02:00 AM on 27th should be duty 26th when shift is night
    $proc = app(AttendanceProcessingService::class);
    $ref = new ReflectionClass($proc);
    $m = $ref->getMethod('resolveDutyDate');
    $m->setAccessible(true);
    $duty = $m->invoke($proc, Carbon::parse('2026-10-27 02:00:00'), $night->id);
    expect($duty)->toBe('2026-10-26');

    $duty2 = $m->invoke($proc, Carbon::parse('2026-10-26 23:00:00'), $night->id);
    expect($duty2)->toBe('2026-10-26');

    // Day shift should not shift date
    $day = $shifts['shift8Day'];
    $duty3 = $m->invoke($proc, Carbon::parse('2026-10-27 02:00:00'), $day->id);
    expect($duty3)->toBe('2026-10-27');
});

// -----------------------------------------------------------------------------
// AUTO SHIFT DETECTION by duty_hours (no roster)
test('auto shift detection picks 6h shift when employee duty_hours=6 and no roster', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 6, 'auto_shift' => true], ['duty_hours' => 6]);
    $shifts = createShiftsForAutoDetect();
    // No roster at all -> must rely on auto_shift

    // Punch at 06:30 falls within Morning 6H (06-12) and matches duration 6h
    processPunch($emp->employee_id, '3001', '2026-10-26 06:30:00');
    $pair = AttendancePair::where('employee_id', $emp->employee_id)->first();
    expect($pair->shift_id)->toBe($shifts['shift6']->id);
    expect($pair->duty_date->toDateString())->toBe('2026-10-26');

    // Complete day: OUT at 12:30 => 6h exact => no overtime
    processPunch($emp->employee_id, '3001', '2026-10-26 12:30:00');
    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->first();
    expect((float) $daily->grand_total_hours)->toBe(6.0);
    expect(RosterOvertime::where('employee_id', $emp->employee_id)->exists())->toBeFalse();
});

test('auto shift detection picks 7h shift when duty_hours=7', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 7, 'auto_shift' => true], ['duty_hours' => 7]);
    $shifts = createShiftsForAutoDetect();

    processPunch($emp->employee_id, '3002', '2026-10-26 14:30:00');
    $pair = AttendancePair::where('employee_id', $emp->employee_id)->first();
    expect($pair->shift_id)->toBe($shifts['shift7']->id);

    processPunch($emp->employee_id, '3002', '2026-10-26 21:30:00'); // 7h
    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->first();
    expect((float) $daily->grand_total_hours)->toBe(7.0);
});

test('auto shift detection picks 8h night shift when duty_hours=8 and punch at 22:10 with no roster', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8, 'auto_shift' => true], ['duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    // No roster - punch at 22:10 should match Night 8H via duration + window
    processPunch($emp->employee_id, '3003', '2026-10-26 22:10:00');
    $pair = AttendancePair::first();
    expect($pair->shift_id)->toBe($shifts['shift8Night']->id);
    expect($pair->duty_date->toDateString())->toBe('2026-10-26');

    processPunch($emp->employee_id, '3003', '2026-10-27 06:10:00');
    $pair->refresh();
    expect((float) $pair->worked_hours)->toBeGreaterThan(7.9)->toBeLessThan(8.2);
    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-26')->first();
    expect($daily)->not->toBeNull();
    expect($daily->primary_punch_out->toDateTimeString())->toBe('2026-10-27 06:10:00');
});

test('auto shift disabled falls back to time window / first shift', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 6, 'auto_shift' => false], ['duty_hours' => 6]);
    $shifts = createShiftsForAutoDetect();

    $proc = app(AttendanceProcessingService::class);
    $ref = new ReflectionClass($proc);
    $m = $ref->getMethod('detectShift');
    $m->setAccessible(true);

    // With auto_shift false, policy is Fixed, no roster, punch at 06:30 should still find Morning 6H via Fixed window match
    $punch = Carbon::parse('2026-10-26 06:30:00');
    $policy = EmployeePolicy::where('employee_id', $emp->employee_id)->first();
    $shiftId = $m->invoke($proc, $punch, $policy, $emp->employee_id);
    // Fixed scans all shifts where time in start-end; 06:30 is in 06-12 only
    expect($shiftId)->toBe($shifts['shift6']->id);
});

// -----------------------------------------------------------------------------
// FIFO duty_date pairing across consecutive days (regression for 24th->25th bug)
test('FIFO pairing prevents 24th IN pairing with 25th IN - two consecutive days each close correctly', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    // Roster for both days
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id, 'roster_date' => '2026-10-24']);
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id, 'roster_date' => '2026-10-25']);

    // Day 24: IN 09:00 OUT 17:00
    processPunch($emp->employee_id, '4001', '2026-10-24 09:00:00');
    processPunch($emp->employee_id, '4001', '2026-10-24 17:00:00');
    // Day 25: IN 09:10 OUT 17:20
    processPunch($emp->employee_id, '4001', '2026-10-25 09:10:00');
    processPunch($emp->employee_id, '4001', '2026-10-25 17:20:00');

    $pairs = AttendancePair::where('employee_id', $emp->employee_id)->orderBy('duty_date')->get();
    expect($pairs->count())->toBe(2);
    expect($pairs[0]->duty_date->toDateString())->toBe('2026-10-24');
    expect($pairs[1]->duty_date->toDateString())->toBe('2026-10-25');
    expect((float) $pairs[0]->worked_hours)->toBe(8.0);
    expect((float) $pairs[1]->worked_hours)->toBeGreaterThan(8.0);

    $d24 = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-24')->first();
    $d25 = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-25')->first();
    expect($d24->status)->toBe('Present');
    expect($d25->status)->toBe('Present');
    expect((float) $d24->grand_total_hours)->toBe(8.0);
});

// -----------------------------------------------------------------------------
// Late / Early detection vs grace
test('late detection when beyond grace 15m - IN 09:20 vs expected 09:00 => Late', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8, 'grace_time_in' => 15]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id,
        'roster_date' => '2026-10-26', 'scheduled_start_time' => '09:00:00', 'scheduled_end_time' => '17:00:00',
    ]);
    processPunch($emp->employee_id, '5001', '2026-10-26 09:20:00');
    processPunch($emp->employee_id, '5001', '2026-10-26 17:00:00');
    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->first();
    expect($daily->status)->toBe('Late');
});

test('present when within grace - IN 09:10 vs 09:00 grace 15 => Present', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8, 'grace_time_in' => 15]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id,
        'roster_date' => '2026-10-26', 'scheduled_start_time' => '09:00:00', 'scheduled_end_time' => '17:00:00',
    ]);
    processPunch($emp->employee_id, '5002', '2026-10-26 09:10:00');
    processPunch($emp->employee_id, '5002', '2026-10-26 17:00:00');
    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->first();
    expect($daily->status)->toBe('Present');
});

// -----------------------------------------------------------------------------
// Batch processPending correctness
test('processPending batch handles 4 logs (2 days) via single call', function () {
    ['emp' => $emp] = fullFlowSetup();
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id, 'roster_date' => '2026-10-26']);
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id, 'roster_date' => '2026-10-27']);

    $logs = [
        ['2026-10-26 09:00:00', false],
        ['2026-10-26 17:00:00', false],
        ['2026-10-27 09:05:00', false],
        ['2026-10-27 17:05:00', false],
    ];
    foreach ($logs as [$time, $processed]) {
        AttendanceLog::factory()->create([
            'employee_id' => $emp->employee_id, 'device_user_id' => '6001',
            'punch_time' => $time, 'is_processed' => $processed,
        ]);
    }
    $processor = app(AttendanceProcessingService::class);
    $result = $processor->processPending(10);
    expect($result['processed'])->toBe(4);
    expect($result['closed_pairs'])->toBe(2);
    expect(AttendancePair::count())->toBe(2);
    expect(AttendanceDaily::count())->toBe(2);
});

// -----------------------------------------------------------------------------
// Helpers unit level
test('shiftDurationHours calculates midnight crossing correctly', function () {
    $proc = app(AttendanceProcessingService::class);
    $ref = new ReflectionClass($proc);
    $m = $ref->getMethod('shiftDurationHours');
    $m->setAccessible(true);
    expect($m->invoke($proc, '09:00:00', '17:00:00', false))->toBe(8.0);
    expect($m->invoke($proc, '06:00:00', '12:00:00', false))->toBe(6.0);
    expect($m->invoke($proc, '22:00:00', '06:00:00', true))->toBe(8.0);
    expect($m->invoke($proc, '14:00:00', '21:00:00', false))->toBe(7.0);
});

test('isTimeInWindow boundary inclusive', function () {
    $proc = app(AttendanceProcessingService::class);
    $ref = new ReflectionClass($proc);
    $m = $ref->getMethod('isTimeInWindow');
    $m->setAccessible(true);
    expect($m->invoke($proc, '09:00:00', '09:00:00', '17:00:00'))->toBeTrue();
    expect($m->invoke($proc, '17:00:00', '09:00:00', '17:00:00'))->toBeTrue();
    expect($m->invoke($proc, '22:00:00', '22:00:00', '06:00:00'))->toBeTrue();
    expect($m->invoke($proc, '06:00:00', '22:00:00', '06:00:00'))->toBeTrue();
    expect($m->invoke($proc, '06:01:00', '22:00:00', '06:00:00'))->toBeFalse();
});

test('overtime auto-generation respects allow_overtime false', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8], ['allow_overtime' => false, 'duty_hours' => 8]);
    $shifts = createShiftsForAutoDetect();
    $dayShift = $shifts['shift8Day'];
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $dayShift->id, 'roster_date' => '2026-10-28']);

    processPunch($emp->employee_id, '7001', '2026-10-28 09:00:00');
    processPunch($emp->employee_id, '7001', '2026-10-28 19:00:00'); // 10h

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->first();
    expect((float) $daily->grand_total_hours)->toBe(10.0);
    expect(RosterOvertime::where('employee_id', $emp->employee_id)->exists())->toBeFalse();
});

test('allow_overtime false does not suppress separately classified holiday extra duty', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8], ['allow_overtime' => false, 'duty_hours' => 8]);
    createShiftsForAutoDetect();

    Holiday::query()->create([
        'name' => 'Extra duty regression holiday',
        'holiday_date' => '2026-10-27',
        'holiday_type' => 'Public Holiday',
    ]);

    processPunch($emp->employee_id, '7002', '2026-10-27 09:00:00');
    processPunch($emp->employee_id, '7002', '2026-10-27 17:00:00');

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-27')->firstOrFail();
    $extraDuty = RosterOvertime::where('employee_id', $emp->employee_id)->whereDate('overtime_date', '2026-10-27')->first();

    expect($daily->status)->toBe('Extra Duty')
        ->and($extraDuty)->not->toBeNull()
        ->and($extraDuty->overtime_type)->toBe('Extra Duty')
        ->and((float) $extraDuty->hours)->toBe(8.0);
});

test('allow_overtime false does not suppress rostered off-day extra duty', function () {
    ['emp' => $emp] = fullFlowSetup(['duty_hours' => 8], ['allow_overtime' => false, 'duty_hours' => 8]);
    $dayShift = createShiftsForAutoDetect()['shift8Day'];
    RosterDuty::factory()->create([
        'employee_id' => $emp->employee_id,
        'shift_id' => $dayShift->id,
        'roster_date' => '2026-10-29',
        'scheduled_start_time' => '09:00:00',
        'scheduled_end_time' => '17:00:00',
        'status' => 'Off',
    ]);

    processPunch($emp->employee_id, '7003', '2026-10-29 09:00:00');
    processPunch($emp->employee_id, '7003', '2026-10-29 17:00:00');

    $daily = AttendanceDaily::where('employee_id', $emp->employee_id)->whereDate('duty_date', '2026-10-29')->firstOrFail();
    $extraDuty = RosterOvertime::where('employee_id', $emp->employee_id)->whereDate('overtime_date', '2026-10-29')->first();

    expect($daily->status)->toBe('Extra Duty')
        ->and($extraDuty)->not->toBeNull()
        ->and($extraDuty->overtime_type)->toBe('Extra Duty')
        ->and((float) $extraDuty->hours)->toBe(8.0);
});

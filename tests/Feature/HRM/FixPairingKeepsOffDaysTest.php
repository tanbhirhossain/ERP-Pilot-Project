<?php

/**
 * Reported from the desk (2026-11-10): "hrm pipeline nicely handles off day, but 'Fix pair' removes the
 * day which is off day".
 *
 * The repair flow deletes every pair + daily in the scoped range and replays attendance_logs. Replaying
 * logs can only rebuild days that HAVE punches — an Off Day row (and Holiday / On Leave rows) is created
 * by pipeline Stage 3 reconciliation from roster/holiday/leave data, which the old fix never invoked.
 * So "fixing" a range silently erased the punch-less days it deleted. The fix now finishes with exactly
 * the Stage 3 the nightly pipeline runs (recalcForDateRange + reconcile).
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\AMZDemoSeeder;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

uses(RefreshDatabase::class);

function fpoFixUser(): User
{
    $user = User::factory()->create();
    foreach (['hrm.attendance.view', 'hrm.attendance.manage'] as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

function fpoEmployee(array $attributes = []): Employee
{
    $department = $attributes['department'] ?? Department::factory()->create();
    unset($attributes['department']);
    $employee = Employee::factory()->create(array_merge([
        'department_id' => $department->id,
        'joining_date' => '2020-01-01',
        'status' => 'Active',
        'is_active' => true,
        'duty_hours' => 8,
    ], $attributes));
    EmployeePolicy::factory()->create([
        'employee_id' => $employee->employee_id,
        'shift_type' => 'Fixed',
        'duty_hours' => 8,
        'is_punishable' => false,
        'grace_time_in' => 10,
        'grace_time_out' => 10,
    ]);

    return $employee;
}

test('a repair rebuilds punched days from logs AND re-creates the punch-less off day it deleted', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = fpoEmployee();
    EmployeeFallbackOffDay::query()->create(['employee_id' => $employee->employee_id, 'day_of_week' => Carbon::FRIDAY]);

    // Oct 7 had real punches (a pair + daily exist); Oct 9 is Friday — a punch-less Off Day row built by
    // the nightly pipeline. The repair deletes BOTH; only Stage 3 can bring the Friday back.
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-10-09', 'status' => 'Off Day', 'remarks' => 'No attendance logs on roster Off day.']);
    AttendanceLog::query()->create(['employee_id' => $employee->employee_id, 'punch_time' => '2026-10-07 15:05:00', 'punch_type' => 'IN', 'is_processed' => false]);
    AttendanceLog::query()->create(['employee_id' => $employee->employee_id, 'punch_time' => '2026-10-07 23:02:00', 'punch_type' => 'OUT', 'is_processed' => false]);

    $this->actingAs(fpoFixUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => $employee->employee_id,
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])
        ->assertSessionHasNoErrors();

    expect(AttendancePair::query()->where('employee_id', $employee->employee_id)->count())->toBe(1)
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-07')->exists())->toBeTrue()
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-09')->value('status'))->toBe('Off Day');

    // And the whole month is now complete the pipeline way: 31 calendar-day rows — one per October date.
    expect(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->count())->toBe(31);
});

test('holiday and approved-leave days survive (and appear) after a repair', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = fpoEmployee();
    EmployeeFallbackOffDay::query()->create(['employee_id' => $employee->employee_id, 'day_of_week' => Carbon::FRIDAY]);
    Holiday::query()->create(['name' => 'Hindu Durga puja', 'holiday_date' => '2026-10-20', 'holiday_type' => 'Public Holiday']);
    Holiday::query()->create(['name' => 'Hindu Durga puja', 'holiday_date' => '2026-10-21', 'holiday_type' => 'Public Holiday']);
    $sick = LeaveType::query()->create(['name' => 'Sick Leave', 'is_paid' => true]);
    LeaveRequest::query()->create(['employee_id' => $employee->employee_id, 'leave_type_id' => $sick->id, 'from_date' => '2026-10-22', 'to_date' => '2026-10-22', 'days' => 1, 'status' => 'Approved']);

    // A punch-less pre-existing Holiday row must not be the only reason it survives: delete them all and
    // let the rebuilt month speak from roster/holiday/leave alone.
    AttendanceDaily::query()->where('employee_id', $employee->employee_id)->delete();

    $this->actingAs(fpoFixUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => $employee->employee_id,
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])
        ->assertSessionHasNoErrors();

    foreach (['2026-10-20' => 'Holiday', '2026-10-21' => 'Holiday', '2026-10-22' => 'On Leave', '2026-10-02' => 'Off Day', '2026-10-08' => 'Absent'] as $date => $status) {
        expect(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', $date)->value('status'))->toBe($status);
    }
});

test('the dry run counts the roster-derived rows the rebuild reclassifies', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = fpoEmployee();
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-10-09', 'status' => 'Off Day']);

    $this->actingAs(fpoFixUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => $employee->employee_id,
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 1,
        ])
        ->assertSessionHas('preview.roster_derived_rows', 1);

    // Nothing changed.
    expect(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->count())->toBe(1);
});

test('AMZDemoSeeder lays the requested October 2026 calendar for L402003 and L1009001', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $this->seed(AMZDemoSeeder::class);

    // Roster: 31 October duties for L402003, exactly the Fridays Off, everyone else on the 15:00–23:00 shift.
    // whereDate, not whereBetween: the model's date cast stores 'Y-m-d H:i:s', so a raw string
    // upper bound would silently drop the last day.
    $duties = RosterDuty::query()->where('employee_id', 'L402003')->whereDate('roster_date', '>=', '2026-10-01')->whereDate('roster_date', '<=', '2026-10-31')->get()->keyBy(fn ($d) => $d->roster_date->toDateString());
    expect($duties)->toHaveCount(31);
    foreach (['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'] as $friday) {
        expect($duties[$friday]->status)->toBe('Off');
    }
    expect($duties['2026-10-12']->status)->toBe('Scheduled')
        ->and(substr((string) $duties['2026-10-12']->scheduled_start_time, 0, 5))->toBe('15:00')
        ->and(substr((string) $duties['2026-10-12']->scheduled_end_time, 0, 5))->toBe('23:00')
        ->and(EmployeeFallbackOffDay::query()->where('employee_id', 'L402003')->value('day_of_week'))->toBe(Carbon::FRIDAY);

    // Approved sick leave on the two requested days.
    expect(LeaveRequest::query()->where('employee_id', 'L402003')->whereDate('from_date', '2026-10-22')->where('status', 'Approved')->exists())->toBeTrue()
        ->and(LeaveRequest::query()->where('employee_id', 'L1009001')->whereDate('from_date', '2026-10-17')->where('status', 'Approved')->exists())->toBeTrue();

    // The holiday calendar.
    expect(Holiday::query()->where('name', 'Hindu Durga puja')->where('holiday_type', 'Public Holiday')
        ->where(fn ($q) => $q->whereDate('holiday_date', '2026-10-20')->orWhereDate('holiday_date', '2026-10-21'))
        ->count())->toBe(2);

    // Materialise the dailies the way the desk does it — pipeline Stage 3 over the seeded calendar.
    app(AttendanceDayReconciliationService::class)->reconcile('2026-10-01', '2026-10-31');

    expect(AttendanceDaily::query()->where('employee_id', 'L402003')->whereDate('duty_date', '2026-10-09')->value('status'))->toBe('Off Day')
        ->and(AttendanceDaily::query()->where('employee_id', 'L402003')->whereDate('duty_date', '2026-10-22')->value('status'))->toBe('On Leave')
        ->and(AttendanceDaily::query()->where('employee_id', 'L402003')->whereDate('duty_date', '2026-10-20')->value('status'))->toBe('Holiday')
        ->and(AttendanceDaily::query()->where('employee_id', 'L1009001')->whereDate('duty_date', '2026-10-17')->value('status'))->toBe('On Leave');

    // Idempotent: a second run changes no counts.
    $before = ['d' => RosterDuty::query()->count(), 'l' => LeaveRequest::query()->count(), 'h' => Holiday::query()->count(), 'a' => AttendanceDaily::query()->count()];
    $this->seed(AMZDemoSeeder::class);
    expect(['d' => RosterDuty::query()->count(), 'l' => LeaveRequest::query()->count(), 'h' => Holiday::query()->count(), 'a' => AttendanceDaily::query()->count()])->toBe($before)
        // The October duty window for L402003 is complete — 31 rows, and whereData or plain where both
        // agree because the seeder keys writes with whereDate (date-cast columns store 'Y-m-d 00:00:00').
        ->and(RosterDuty::query()->where('employee_id', 'L402003')->count())->toBe(31);
});

test('the MAIN DatabaseSeeder lays the reference data and the October calendar', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $this->seed(DatabaseSeeder::class);

    // The two seeders that existed but were never wired in: shifts and leave types.
    expect(RosterShift::query()->where('shift_name', 'Evening 03PM-11PM')->exists())->toBeTrue()
        ->and(LeaveType::query()->where('name', 'Sick Leave')->where('is_paid', true)->exists())->toBeTrue();

    // And the requested calendar itself.
    expect(RosterDuty::query()->where('employee_id', 'L402003')->whereDate('roster_date', '2026-10-09')->value('status'))->toBe('Off')
        ->and(RosterDuty::query()->where('employee_id', 'L402003')->whereDate('roster_date', '2026-10-12')->value('scheduled_start_time'))->toContain('15:00')
        ->and(LeaveRequest::query()->where('employee_id', 'L1009001')->whereDate('from_date', '2026-10-17')->where('status', 'Approved')->exists())->toBeTrue()
        ->and(Holiday::query()->where('name', 'Hindu Durga puja')->whereDate('holiday_date', '2026-10-20')->exists())->toBeTrue();
});

test('a repair over a rostered demo employee keeps every Friday classified after the rebuild', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $this->seed(AMZDemoSeeder::class);
    AttendancePair::query()->delete(); // pretend the pairs went stale and must be rebuilt

    $this->actingAs(fpoFixUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => 'L402003',
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])
        ->assertSessionHasNoErrors();

    foreach (['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'] as $friday) {
        expect(AttendanceDaily::query()->where('employee_id', 'L402003')->whereDate('duty_date', $friday)->value('status'))->toBe('Off Day');
    }
});

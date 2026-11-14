<?php

/**
 * "Fix Attendance Pairing will be a job which will run on background — it destroys the other status
 * records. Suppose attendance is 28 to 31 rows a month, off day / leave / holiday all have a row, but
 * when we fix the pair, everything except the days with a punch is removed." (2026-11-10)
 *
 * §16 made the repair finish with pipeline Stage 3, which fixed the *classification* half. Two ways to
 * lose a day survived it, and both are pinned here:
 *
 *  1. **Nobody owns a month-sized rebuild in a web request.** The clear was committed on its own before
 *     the rebuild ran, so a request killed by the PHP-FPM/nginx timeout — routine for a department or
 *     global scope, which reconciles ~1,000 employees × 31 days — left the range cleared and unrebuild.
 *     The work is now {@see FixAttendancePairingJob}, and the page polls a status key instead of waiting.
 *  2. **A rebuild can only rebuild what it can re-derive.** The reconciler walks *active* employees and
 *     refuses to finalise today; `recalcForDateRange()` compared a date-cast column against a bare upper
 *     bound, so the range's last day was recalculated by nobody (a pair existed, so the reconciler
 *     politely stepped aside) — punch-derived days silently missing on exactly the 31st the report names.
 *
 * So: the range is clamped to what can be finalised, and every punch-less calendar row (plus approved
 * regularizations, which reconcile never produces) snapshotted before the clear is put back if the
 * rebuild cannot reproduce it — restored when absent, or replacing a synthetic `Absent` when the
 * pipeline would otherwise downgrade a corrected day.
 *
 * Helpers are prefixed `arp`.
 */

use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;
use Modules\HRM\Jobs\FixAttendancePairingJob;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceRepairService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function arpUser(): User
{
    $user = User::factory()->create();

    foreach (['hrm.attendance.view', 'hrm.attendance.manage'] as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

function arpEmployee(array $attributes = []): Employee
{
    $employee = Employee::factory()->create(array_merge([
        'department_id' => Department::factory()->create()->id,
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

function arpShift(): RosterShift
{
    return RosterShift::query()->create([
        'shift_name' => 'Evening 03PM-11PM',
        'start_time' => '15:00:00',
        'end_time' => '23:00:00',
        'punch_in_window_start' => '14:00:00',
        'punch_in_window_end' => '16:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
}

function arpRepair(Employee $employee): array
{
    return app(AttendanceRepairService::class)->repair('2026-10-01', '2026-10-31', [$employee->employee_id]);
}

test('applying a repair queues a job and does not touch a single row in the request', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-10-09', 'status' => 'Off Day']);
    AttendanceLog::query()->create(['employee_id' => $employee->employee_id, 'punch_time' => '2026-10-07 15:05:00', 'punch_type' => 'IN', 'is_processed' => false]);

    Queue::fake();

    $this->actingAs(arpUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => $employee->employee_id,
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Repair queued'));

    Queue::assertPushed(FixAttendancePairingJob::class, fn (FixAttendancePairingJob $job): bool => $job->from === '2026-10-01'
        && $job->to === '2026-10-31'
        && $job->employeeIds === [$employee->employee_id]);

    // The whole point: nothing destructive happened inside the request. A killed request used to be the
    // bug — now a killed request is merely a job that has not started.
    expect(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->count())->toBe(1)
        ->and(AttendanceLog::query()->where('employee_id', $employee->employee_id)->value('is_processed'))->toBe(false)
        ->and(AttendancePair::query()->count())->toBe(0);
});

test('a running repair blocks a second one instead of letting two jobs fight over the same rows', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();
    Queue::fake();
    Cache::put(AttendanceRepairService::STATUS_KEY, ['state' => 'running', 'scope' => 'all employees', 'from' => '2026-10-01', 'to' => '2026-10-31', 'step' => 'reconciling'], now()->addHour());

    $this->actingAs(arpUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'already running'));

    Queue::assertNothingPushed();

    // A dry run is still allowed: it reads, so it cannot collide with the running rebuild.
    $this->actingAs(arpUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'employee', 'employee_id' => $employee->employee_id,
            'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 1,
        ])
        ->assertSessionHasNoErrors();
});

test('the status endpoint reports what the queued job is doing and what it did', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    arpEmployee();

    $this->actingAs(arpUser())->getJson(route('hrm.attendance.fix.status'))->assertOk()->assertJson(['state' => 'idle']);

    $this->actingAs(arpUser())
        ->post(route('hrm.attendance.fix'), [
            'scope' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-31', 'dry_run' => 0,
        ])->assertSessionHasNoErrors();

    $this->actingAs(arpUser())->getJson(route('hrm.attendance.fix.status'))
        ->assertOk()
        ->assertJsonPath('state', 'done')
        ->assertJsonPath('scope', 'all employees')
        ->assertJsonStructure(['started_at', 'updated_at', 'result' => ['reconcile', 'cleared', 'restored', 'replaced', 'message']])
        ->assertJsonPath('result.ok', true)
        ->assertJsonPath('result.message', fn (string $message): bool => str_contains($message, 'Repaired 2026-10-01 through 2026-10-31'));
});

test('a punch-less calendar day the calendar cannot reproduce is put back, not lost', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    // Left the company on 2026-10-08. The reconciler deliberately skips every date after an approved
    // last working day, so nothing on its side would bring this Friday's Off Day row back — before this
    // change the repair deleted it and the leaver's month silently lost a day.
    $employee = arpEmployee();
    EmployeeResignation::query()->create([
        'employee_id' => $employee->employee_id,
        'status' => 'Approved',
        'last_working_date' => '2026-10-08',
        'reason' => 'Resigned for the purposes of this test.',
        'approved_at' => '2026-09-25 09:00:00',
    ]);
    AttendanceDaily::query()->create([
        'employee_id' => $employee->employee_id,
        'duty_date' => '2026-10-09',
        'status' => 'Off Day',
        'remarks' => 'No attendance logs on roster Off day.',
    ]);

    $result = arpRepair($employee);

    expect($result['ok'])->toBeTrue()
        ->and($result['restored'])->toBe(1)
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-09')->count())->toBe(1)
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-09')->value('status'))->toBe('Off Day')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-09')->value('remarks'))->toContain('kept by pairing repair');
});

test('an approved regularization is never downgraded to Absent by a repair', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();
    EmployeeFallbackOffDay::query()->create(['employee_id' => $employee->employee_id, 'day_of_week' => Carbon::FRIDAY]);

    // Wednesday 2026-10-07: a missing punch an officer fixed by hand, so it has no punches and no roster
    // evidence — exactly the row Stage 3 would re-derive as Absent if the repair deleted it first.
    AttendanceDaily::query()->create([
        'employee_id' => $employee->employee_id,
        'duty_date' => '2026-10-07',
        'status' => 'Present',
        'remarks' => 'Regularized: employee was on duty, punch lost.',
        'is_regularized' => true,
    ]);

    $result = arpRepair($employee);
    $after = AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-07')->first();

    expect($result['replaced'])->toBe(1)
        ->and($after)->not->toBeNull()
        ->and($after->status)->toBe('Present')
        ->and((bool) $after->is_regularized)->toBeTrue()
        // A real workday next to it is still rebuilt from the calendar, so this is not "keep everything".
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-08')->value('status'))->toBe('Absent');
});

test('a repaired month keeps exactly one row per day, the 31st included', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();
    $shift = arpShift();
    EmployeeFallbackOffDay::query()->create(['employee_id' => $employee->employee_id, 'day_of_week' => Carbon::FRIDAY]);

    for ($day = 1; $day <= 31; $day++) {
        $date = sprintf('2026-10-%02d', $day);
        $isFriday = Carbon::parse($date)->isFriday();
        RosterDuty::query()->create([
            'employee_id' => $employee->employee_id,
            'roster_date' => $date,
            'shift_id' => $isFriday ? null : $shift->id,
            'scheduled_start_time' => $isFriday ? null : '15:00:00',
            'scheduled_end_time' => $isFriday ? null : '23:00:00',
            'status' => $isFriday ? 'Off' : 'Scheduled',
        ]);
    }

    // Punches on the 7th and — pointedly — on the 31st, the day a date-cast `whereBetween` upper bound
    // used to exclude from recalcForDateRange().
    foreach (['2026-10-07', '2026-10-31'] as $date) {
        AttendanceLog::query()->create(['employee_id' => $employee->employee_id, 'punch_time' => $date.' 15:05:00', 'punch_type' => 'IN', 'is_processed' => false]);
        AttendanceLog::query()->create(['employee_id' => $employee->employee_id, 'punch_time' => $date.' 23:02:00', 'punch_type' => 'OUT', 'is_processed' => false]);
    }

    $result = arpRepair($employee);
    $dailies = AttendanceDaily::query()->where('employee_id', $employee->employee_id)->get()->keyBy(fn ($row) => $row->duty_date->toDateString());

    expect($result['ok'])->toBeTrue()
        ->and($dailies)->toHaveCount(31)
        ->and($dailies->get('2026-10-31')?->primary_punch_in)->not->toBeNull()
        ->and($dailies->get('2026-10-07')?->primary_punch_in)->not->toBeNull()
        ->and($dailies->get('2026-10-09')?->status)->toBe('Off Day')
        ->and($dailies->get('2026-10-16')?->status)->toBe('Off Day')
        ->and($dailies->get('2026-10-08')?->status)->toBe('Absent')
        ->and(AttendancePair::query()->where('employee_id', $employee->employee_id)->count())->toBe(2);
});

test('a repair never clears a day it cannot finalise', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-11-05', 'status' => 'Off Day']);
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-11-04', 'status' => 'Holiday']);

    $result = app(AttendanceRepairService::class)->repair('2026-11-01', '2026-11-05', [$employee->employee_id]);

    expect($result['ok'])->toBeTrue()
        ->and($result['to'])->toBe('2026-11-04')
        ->and($result['clipped'])->toBeTrue()
        ->and($result['message'])->toContain('cut short at 2026-11-04')
        // 11-05 (today) was never in range, and yesterday's Holiday row came back from the snapshot.
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-11-05')->value('status'))->toBe('Off Day')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-11-04')->value('status'))->toBe('Holiday');

    // A range entirely beyond the finalisation line does nothing at all rather than clearing the month.
    $later = app(AttendanceRepairService::class)->repair('2026-11-05', '2026-11-30', [$employee->employee_id]);
    expect($later['ok'])->toBeFalse()
        ->and($later['reason'])->toBe('nothing_finalisable')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-11-05')->exists())->toBeTrue();
});

test('the safety net covers the first day of the range, not just the middle', function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $employee = arpEmployee();

    // 2026-10-01 is `$from` itself. The clear and the rebuild both use `whereDate`, so they include it;
    // the snapshot is the one query that has to agree with them, and a `duty_date BETWEEN '… 00:00:00'`
    // predicate silently drops exactly this day against a `date` column.
    AttendanceDaily::query()->create([
        'employee_id' => $employee->employee_id,
        'duty_date' => '2026-10-01',
        'status' => 'Present',
        'remarks' => 'Regularized on the first of the month.',
        'is_regularized' => true,
    ]);
    AttendanceDaily::query()->create([
        'employee_id' => $employee->employee_id,
        'duty_date' => '2026-10-02',
        'status' => 'Off Day',
    ]);

    $result = arpRepair($employee);

    expect($result['calendar_rows_at_risk'])->toBe(2)
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-01')->value('status'))->toBe('Present')
        ->and(AttendanceDaily::query()->where('employee_id', $employee->employee_id)->whereDate('duty_date', '2026-10-02')->value('status'))->toBe('Off Day');
});

it('releases the queue lock when the repair dies, so the next click is not swallowed', function (): void {
    $job = new FixAttendancePairingJob('2026-10-01', '2026-10-31', null);
    $lockKey = UniqueLock::getKey($job);

    // A worker that picks the job up holds the unique lock for the whole run.
    expect(Cache::lock($lockKey, 3600)->get())->toBeTrue();

    $job->failed(new RuntimeException('worker died'));

    // Without the release in failed(), this stays false for the whole of `uniqueFor` (an hour) and the
    // operator's next "Fix pairing" click disappears with a success toast and no job.
    expect(Cache::lock($lockKey, 3600)->get())->toBeTrue()
        ->and(Cache::get(AttendanceRepairService::STATUS_KEY))->toMatchArray(['state' => 'failed'])
        ->and(Cache::get(AttendanceRepairService::STATUS_KEY)['step'])->toContain('worker died');
});

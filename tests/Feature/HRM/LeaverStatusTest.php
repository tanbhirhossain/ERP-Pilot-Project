<?php

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Services\EmployeeResignationService;
use Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider;
use Modules\PAYROLL\Services\HRM\HrmEmployeePayrollDataProvider;

/**
 * P36 — a leaver's record, and the three things that must all agree about it.
 *
 * Approving a resignation wrote the resignation row and nothing else: `employees.status` stayed
 * `Active` for ever, so HR screens called a departed person current staff, while payroll — which
 * excludes them by a separate `NOT EXISTS` on `employee_resignations` — treated them as gone. The
 * visible source of truth was the wrong one.
 *
 * The fix writes the employee row inside the approval transaction, but the *when* is the whole
 * subtlety, and these tests are written around it:
 *
 *   1. The **label** moves on approval (`Resigned`), because HR wants to see "leaving on the 20th"
 *      from the moment it is agreed.
 *   2. The **employment flag** (`is_active`) only drops once the last working day has arrived. A
 *      resignation agreed today with a three-month notice period must not remove the person from
 *      payroll today — they are still working, and still owed a salary for every day of that notice.
 *   3. Everything that selects employees by `status = 'Active'` must ask the date instead, so a
 *      future-dated leaver is still paid, still accrued leave, still given a fund account.
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function p36Employee(string $id, array $overrides = []): Employee
{
    return Employee::query()->create(array_merge([
        'employee_id' => $id,
        'first_name' => "Emp {$id}",
        'last_name' => 'Test',
        'full_name' => "Emp {$id}",
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => 1,
        'joining_date' => '2019-01-01',
        'gender' => 'Male',
        'duty_hours' => 8,
    ], $overrides));
}

function p36Service(): EmployeeResignationService
{
    return app(EmployeeResignationService::class);
}

/*
 * 1. The label moves on approval — this is the part that used to be missing entirely.
 */
test('approving a resignation writes the employee status, even for a last day still in the future', function () {
    p36Employee('P36-A');

    $resignation = p36Service()->request([
        'employee_id' => 'P36-A',
        'last_working_date' => now()->addMonths(3)->toDateString(),
        'reason' => 'Moving abroad',
    ], null);

    p36Service()->decide($resignation, 'Approved', null);

    $employee = Employee::query()->where('employee_id', 'P36-A')->first();

    expect($employee->status)->toBe('Resigned')
        // …but the employment itself has not ended, so payroll must keep paying.
        ->and((bool) $employee->is_active)->toBeTrue()
        ->and($employee->hasLeftOnOrBefore(now()->toDateString()))->toBeFalse();
});

/*
 * 2. The employment flag drops only when the last day has arrived. A leaver whose last day is today
 *    or in the past is settled at once: there is no notice period left to protect.
 */
test('the employment flag drops when the last working day has arrived, not when the resignation is agreed', function () {
    p36Employee('P36-PAST');

    $resignation = p36Service()->request([
        'employee_id' => 'P36-PAST',
        'last_working_date' => now()->toDateString(),
        'reason' => 'Last day today',
    ], null);

    p36Service()->decide($resignation, 'Approved', null);

    $employee = Employee::query()->where('employee_id', 'P36-PAST')->first();

    expect($employee->status)->toBe('Resigned')
        ->and((bool) $employee->is_active)->toBeFalse()
        ->and($employee->hasLeftOnOrBefore(now()->toDateString()))->toBeTrue();
});

/*
 * 3. A rejected resignation changes nothing: the employee is staying, and a status wiped on a
 *    rejection would be the same bug pointing the other way.
 */
test('rejecting a resignation leaves the employee untouched', function () {
    p36Employee('P36-KEEP');

    $resignation = p36Service()->request([
        'employee_id' => 'P36-KEEP',
        'last_working_date' => now()->addMonth()->toDateString(),
        'reason' => 'Reconsidering',
    ], null);

    p36Service()->decide($resignation, 'Rejected', null, 'We convinced them to stay.');

    $employee = Employee::query()->where('employee_id', 'P36-KEEP')->first();

    expect($employee->status)->toBe('Active')
        ->and((bool) $employee->is_active)->toBeTrue();
});

/*
 * 4. The payroll roster must still pick up somebody serving notice — the whole point of writing the
 *    label early is that the flag, not the label, decides. If this fails, an approved resignation
 *    becomes an unpaid notice period.
 */
test('payroll still selects somebody serving notice, and stops selecting them once the last day has passed', function () {
    p36Employee('P36-NOTICE');

    DB::table('employee_salaries')->insert([
        'employee_id' => 'P36-NOTICE',
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'effective_from' => '2020-01-01',
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lastDay = now()->addDays(40)->toDateString();
    $resignation = p36Service()->request([
        'employee_id' => 'P36-NOTICE',
        'last_working_date' => $lastDay,
        'reason' => 'Notice period',
    ], null);
    p36Service()->decide($resignation, 'Approved', null);

    $provider = app(HrmEmployeePayrollDataProvider::class);

    // This month: still employed, still paid. The label says `Resigned`, the flag stays on.
    $thisMonth = $provider->employeeForPayroll(
        'P36-NOTICE',
        now()->startOfMonth()->toDateString(),
        now()->endOfMonth()->toDateString(),
    );
    expect($thisMonth)->not->toBeNull()
        ->and((float) $thisMonth->grossSalary)->toBe(30000.0);

    // A period starting the month after the last working day: gone, and correctly not slipped.
    // Anchored to $lastDay, not to `now()`: now()+60's month-start can fall BEFORE now()+40's last
    // day near a month boundary (Nov 28 runs Jan 1 against a Jan 7 last day), and a month that was
    // partly worked IS paid, pro-rated — the app is right, the old period was wrong.
    $afterMonth = \Carbon\Carbon::parse($lastDay)->addMonth();
    $after = $provider->employeeForPayroll(
        'P36-NOTICE',
        $afterMonth->copy()->startOfMonth()->toDateString(),
        $afterMonth->copy()->endOfMonth()->toDateString(),
    );
    expect($after)->toBeNull();
});

/*
 * 5. Absence docking follows the same date. A leaver whose last day has passed is never docked; a
 *    person on notice is docked exactly like anybody else, because they are still at work.
 */
test('a person serving notice is still docked for absence, and stops being docked after their last day', function () {
    // Two people, one period: the period ends *between* their last days, so one of them has left
    // during it and the other has not. Same absence, same dates — only the employment date differs.
    $periodEnd = now()->addDays(5)->toDateString();

    // P36-GONE left on the 2nd of the month, before the absence on the 3rd; P36-DOCK is still on the
    // roster for the whole of it. The period is the same for both, so only the date can explain the
    // difference — which is the point: a leaver is charged up to their last day and not one day after.
    foreach (['P36-DOCK' => now()->addDays(40)->toDateString(), 'P36-GONE' => now()->startOfMonth()->addDay()->toDateString()] as $id => $lastDay) {
        p36Employee($id);
        DB::table('employee_policies')->insert([
            'employee_id' => $id, 'grace_time_in' => 15, 'grace_time_out' => 15, 'shift_type' => 'Fixed',
            'is_punishable' => true, 'duty_hours' => 8, 'auto_shift' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resignation = p36Service()->request(['employee_id' => $id, 'last_working_date' => $lastDay, 'reason' => 'P36'], null);
        p36Service()->decide($resignation, 'Approved', null);

        // Both are demonstrably at work this month (one early punch is the gate the absence loop
        // needs), and both have an Absent row on a plain working day inside the period.
        DB::table('attendance_logs')->insert([
            'employee_id' => $id, 'device_id' => null,
            'punch_time' => now()->startOfMonth()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'punch_type' => 'IN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendance_dailies')->insert([
            'employee_id' => $id,
            'duty_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'status' => 'Absent', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $service = app(HrmAttendancePayrollDataProvider::class);
    $start = now()->startOfMonth()->toDateString();
    $end = $periodEnd;

    // The one still employed is docked for the day they were absent…
    DB::table('payroll_settings')->updateOrInsert(['id' => 1], ['missing_attendance_policy' => 'apply']);
    DB::table('payroll_settings')->where('id', 1)->update(['missing_attendance_policy' => 'apply']);
    $stillHere = $service->summaryForEmployee('P36-DOCK', $start, $end);
    $gone = $service->summaryForEmployee('P36-GONE', $start, $end);

    // The absence loop only runs for somebody with punch evidence, and nobody here punched — so the
    // audit key is absent, not wrong. What matters is that the leaver *can* be docked at all: their
    // employment has not ended by the period end, so they are treated like anyone else.
    $breakdown = $stillHere->sourceBreakdown;
    expect(array_key_exists('last_working_day', $breakdown))->toBeTrue()
        ->and($breakdown['last_working_day'])->toBe(now()->addDays(40)->toDateString())
        ->and($stillHere->unpaidLeaveDays)->toBeGreaterThan(0.0)
        // The last day has passed: settled by the final settlement, not by docking a salary.
        ->and($gone->unpaidLeaveDays)->toBe(0.0);
});

/*
 * 6. The model question itself, in both directions, including the fallback for records that predate
 *    the resignation table (nobody has a resignation row, so the hand-written label is all there is).
 */
test('hasLeftOnOrBefore answers from the resignation date, and falls back to the label only without one', function () {
    $future = p36Employee('P36-M-FUT');
    $past = p36Employee('P36-M-PAST');
    $legacy = p36Employee('P36-M-OLD', ['status' => 'Resigned', 'is_active' => 0]);

    foreach ([['P36-M-FUT', now()->addMonth()->toDateString()], ['P36-M-PAST', now()->subMonth()->toDateString()]] as [$id, $day]) {
        EmployeeResignation::query()->create([
            'employee_id' => $id, 'last_working_date' => $day, 'reason' => 'P36 model test',
            'status' => 'Approved', 'approved_at' => now(),
        ]);
    }

    expect($future->fresh()->hasLeftOnOrBefore())->toBeFalse()
        ->and($past->fresh()->hasLeftOnOrBefore())->toBeTrue()
        // No row to read: the label is the only evidence there is, and it says gone.
        ->and($legacy->fresh()->hasLeftOnOrBefore())->toBeTrue()
        // …and the same question asked about a *period end* rather than today: an employment ending
        // in a month's time means the person is still employed at the end of *this* month.
        ->and($future->fresh()->isStillEmployedOn(now()->endOfMonth()->toDateString()))->toBeTrue()
        ->and($future->fresh()->isStillEmployedOn(now()->addMonths(2)->toDateString()))->toBeFalse();
});

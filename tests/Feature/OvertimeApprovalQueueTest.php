<?php

/**
 * The overtime approval queue must read attendance regardless of how `duty_date` was stored.
 *
 * The reported symptom (2026-11-13): `attendance_dailies` held thousands of days with
 * `total_overtime_hours > 0.5`, yet `/hrm/overtimes` showed an empty queue. Two independent faults:
 *
 *  1. `RosterOvertimeService::syncFromAttendance()` refused to queue anything for employees whose
 *     `allow_overtime` was 0. The Excel import had left 997 of 998 employees at 0, so the gate
 *     silently discarded every candidate day. The gate is a legitimate business switch and is kept —
 *     the imported data was corrected instead — and `it keeps the allow_overtime opt-in gate` pins
 *     that the switch still works.
 *
 *  2. `RosterOvertimeController::queue()` hydrated the page's source hours with
 *     `whereIn('duty_date', $dates)`. `attendance_dailies.duty_date` is stored in *both* shapes —
 *     `2026-10-17` and `2026-10-17 00:00:00` — so a plain string comparison matched only the
 *     date-only half and every row rendered `ATTENDANCE HOURS 0.00 h`. Comparing on `date(duty_date)`
 *     fixes it; these tests pin both storage shapes and the mix of the two on one page.
 */

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\RosterOvertimeService;

uses(RefreshDatabase::class);

/** A daily row written exactly as a given importer would have written it. */
function otDaily(string $employeeId, string $rawDate, float $ot, float $grand = 10.0): AttendanceDaily
{
    $id = DB::table('attendance_dailies')->insertGetId([
        'employee_id' => $employeeId,
        'duty_date' => $rawDate,
        'primary_worked_hours' => $grand - $ot,
        'total_overtime_hours' => $ot,
        'grand_total_hours' => $grand,
        'status' => 'Present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return AttendanceDaily::query()->findOrFail($id);
}

/**
 * The rows the approval queue would show, read from the same service the controller calls.
 *
 * The page's per-row source hours are computed in `RosterOvertimeController::queue()` from the
 * attendance-daily keyed map, so this helper mirrors that lookup: it is the join that broke.
 */
function otQueueRows(string $kind = 'Overtime'): array
{
    $dt = \Modules\CORE\Support\DataTableQuery::fromRequest(
        \Illuminate\Http\Request::create('/hrm/overtimes', 'GET'),
        ['id', 'overtime_date', 'hours', 'created_at'],
        ['employee_id', 'department_id', 'designation_id'],
    );

    $page = app(RosterOvertimeService::class)->attendanceApprovalQueue($dt, $kind);
    $rows = $page->getCollection();

    $dates = $rows->pluck('overtime_date')->map(fn ($d) => $d?->toDateString())->filter()->unique();
    $employees = $rows->pluck('employee_id')->unique();

    // The exact lookup the controller performs — this is the line the regression broke.
    $dailyByKey = AttendanceDaily::query()
        ->whereIn('employee_id', $employees)
        ->whereIn(DB::raw('date(duty_date)'), $dates->all())
        ->get()
        ->keyBy(fn ($daily) => $daily->employee_id.'|'.$daily->duty_date?->toDateString());

    return $rows->map(function (RosterOvertime $row) use ($dailyByKey, $kind): array {
        $daily = $dailyByKey->get($row->employee_id.'|'.$row->overtime_date?->toDateString());

        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'overtime_date' => $row->overtime_date?->toDateString(),
            'source_attendance_hours' => (float) ($kind === 'Overtime'
                ? ($daily?->total_overtime_hours ?? 0)
                : ($daily?->grand_total_hours ?? 0)),
            'attendance_overtime_hours' => (float) ($daily?->total_overtime_hours ?? 0),
            'worked_hours' => (float) ($daily?->grand_total_hours ?? 0),
            'attendance_status' => $daily?->status,
        ];
    })->all();
}

beforeEach(function (): void {
    // These tests assert behaviour (queue contents, the approval transition), not Spatie's plumbing.
    // The database is built from migrations alone, so no roles or permissions are seeded; rather than
    // rebuild the ACL graph per test, let the acting user through the abilities these routes ask for.
    // Spatie's PermissionMiddleware calls `$user->canAny([...])`, which consults this same Gate.
    $abilities = ['hrm.overtimes.view', 'hrm.overtimes.manage', 'hrm.overtimes.approve'];
    Gate::before(fn ($actor, string $ability) => $actor instanceof User && in_array($ability, $abilities, true)
        ? true
        : null);

    $this->employee = Employee::factory()->create(['allow_overtime' => 1]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('queues overtime for attendance days above the 30 minute threshold', function (): void {
    otDaily($this->employee->employee_id, '2026-10-17', 3.5, 11.5);

    $result = app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    expect($result['created'])->toBe(1);
    expect(RosterOvertime::query()->where('employee_id', $this->employee->employee_id)
        ->where('overtime_type', 'Overtime')->where('status', 'Pending')->count())->toBe(1);
});

it('keeps the allow_overtime opt-in gate', function (): void {
    // The switch is real: an employee explicitly opted out must not get a queue row.
    $this->employee->update(['allow_overtime' => 0]);
    otDaily($this->employee->employee_id, '2026-10-17', 4.0, 12.0);

    $result = app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    expect($result['created'])->toBe(0);
    expect($result['skipped'])->toBeGreaterThan(0);
});

it('reports source hours when duty_date is stored date-only', function (): void {
    otDaily($this->employee->employee_id, '2026-10-17', 3.25, 11.25);
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    $requests = otQueueRows();
    $row = collect($requests)->firstWhere('employee_id', $this->employee->employee_id);

    expect($row)->not->toBeNull()
        ->and((float) $row['source_attendance_hours'])->toBe(3.25)
        ->and((float) $row['attendance_overtime_hours'])->toBe(3.25)
        ->and((float) $row['worked_hours'])->toBe(11.25)
        ->and($row['attendance_status'])->toBe('Present');
});

it('reports source hours when the same date is stored as a datetime', function (): void {
    // The regression: a 19-character duty_date was invisible to whereIn('duty_date', [...dates]).
    otDaily($this->employee->employee_id, '2026-10-17 00:00:00', 4.75, 12.75);
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    $requests = otQueueRows();
    $row = collect($requests)->firstWhere('employee_id', $this->employee->employee_id);

    expect($row)->not->toBeNull()
        ->and((float) $row['source_attendance_hours'])->toBe(4.75)
        ->and((float) $row['attendance_overtime_hours'])->toBe(4.75)
        ->and((float) $row['worked_hours'])->toBe(12.75);
});

it('reports source hours when both storage shapes appear side by side', function (): void {
    $other = Employee::factory()->create(['allow_overtime' => 1]);
    otDaily($this->employee->employee_id, '2026-10-17', 2.5, 10.5);
    otDaily($other->employee_id, '2026-10-17 00:00:00', 5.5, 13.5);
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    $requests = otQueueRows();
    $a = collect($requests)->firstWhere('employee_id', $this->employee->employee_id);
    $b = collect($requests)->firstWhere('employee_id', $other->employee_id);

    // Both rows must carry their own attendance figures; the mixed shapes must not collapse either to 0.
    expect((float) $a['source_attendance_hours'])->toBe(2.5)
        ->and((float) $b['source_attendance_hours'])->toBe(5.5);
});

it('approves a queued row and moves it out of the pending queue', function (): void {
    otDaily($this->employee->employee_id, '2026-10-17 00:00:00', 3.0, 11.0);
    app(RosterOvertimeService::class)->syncFromAttendance('Overtime', '2026-10-01', '2026-10-31');

    $row = RosterOvertime::query()->where('employee_id', $this->employee->employee_id)->firstOrFail();

    $this->post("/hrm/overtimes/{$row->id}/approve", ['hours' => 3.0])->assertRedirect();

    $row->refresh();
    expect($row->status)->toBe('Approved')
        ->and($row->approved_at)->not->toBeNull()
        ->and($row->approved_by)->toBe($this->user->id);
});

<?php

/**
 * The approval queue's date-range filter, its footer totals and its bulk action.
 *
 * Added after the queue was brought back to life (P10): with 2,349 pending days the operator's first
 * question was "show me last week", and their second was "how many hours is that, and can I approve
 * them together". Three things are pinned here, in the order they were asked for:
 *
 *  1. A date range is inclusive at both ends and compared on `date()`, because `overtime_date` is
 *     stored with a 00:00:00 time component — a plain `<= '2026-10-17'` drops the 17th itself.
 *  2. The footer sums the whole filtered set, not the visible page. A page-local total changes every
 *     time the operator pages, which is the opposite of what a total is for.
 *  3. Bulk approval is not a second, laxer route to money: each row goes through the same
 *     `approve()` that the single-row button calls, and a row the business rules refuse is reported
 *     and skipped rather than aborting the batch.
 */

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\CORE\Models\User;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\RosterOvertimeService;

uses(RefreshDatabase::class);

/** A pending overtime row plus the attendance day it was derived from. */
function otQueueRow(Employee $employee, string $date, float $ot, string $rawDate = null): RosterOvertime
{
    $raw = $rawDate ?? ($date.' 00:00:00');

    DB::table('attendance_dailies')->insert([
        'employee_id' => $employee->employee_id,
        'duty_date' => $raw,
        'primary_worked_hours' => 8.0,
        'total_overtime_hours' => $ot,
        'grand_total_hours' => 8.0 + $ot,
        'status' => 'Present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return RosterOvertime::query()->create([
        'employee_id' => $employee->employee_id,
        'overtime_date' => $raw,
        'overtime_type' => 'Overtime',
        'hours' => $ot,
        'status' => 'Pending',
        'reason' => 'Backfilled from existing attendance daily record.',
    ]);
}

/** A DataTableQuery carrying just the filters the test cares about. */
function otQuery(array $filters = []): DataTableQuery
{
    return new DataTableQuery(filters: $filters, perPage: 25);
}

beforeEach(function (): void {
    $abilities = ['hrm.overtimes.view', 'hrm.overtimes.manage', 'hrm.overtimes.approve'];
    Gate::before(fn ($actor, string $ability) => $actor instanceof User && in_array($ability, $abilities, true)
        ? true
        : null);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('filters the queue by an inclusive date range', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);
    otQueueRow($employee, '2026-10-09', 1.0);
    otQueueRow($employee, '2026-10-10', 2.0);
    otQueueRow($employee, '2026-10-12', 3.0);
    otQueueRow($employee, '2026-10-20', 4.0);

    $service = app(RosterOvertimeService::class);

    // Both ends inclusive: 10-10 and 10-12 are inside, 10-09 and 10-20 are not.
    $page = $service->attendanceApprovalQueue(otQuery(['date_from' => '2026-10-10', 'date_to' => '2026-10-12']), 'Overtime');

    // `hours` is a decimal cast, so it arrives as a string — compare what the page actually shows.
    expect($page->total())->toBe(2)
        ->and($page->getCollection()->pluck('hours')->map(fn ($h) => (float) $h)->sort()->values()->all())
        ->toBe([2.0, 3.0]);
});

it('totals the whole filtered set rather than the visible page', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);

    foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $index => $date) {
        otQueueRow($employee, $date, 1.0 + $index);
    }

    // Five rows, one per page: a page-local total would report only the first row's 1.0 h.
    $query = new DataTableQuery(filters: [], perPage: 1);
    $service = app(RosterOvertimeService::class);

    $page = $service->attendanceApprovalQueue($query, 'Overtime');
    $totals = $service->queueTotals($query, 'Overtime');

    expect($page->count())->toBe(1)
        ->and($totals['days'])->toBe(5)
        ->and($totals['approved_hours'])->toBe(15.0)
        ->and($totals['employees'])->toBe(1);
});

it('keeps the totals in step with the range filter', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);
    otQueueRow($employee, '2026-10-10', 2.5);
    otQueueRow($employee, '2026-10-11', 4.0);
    otQueueRow($employee, '2026-10-20', 9.0);

    $service = app(RosterOvertimeService::class);
    $totals = $service->queueTotals(otQuery(['date_from' => '2026-10-10', 'date_to' => '2026-10-11']), 'Overtime');

    expect($totals['days'])->toBe(2)
        ->and($totals['approved_hours'])->toBe(6.5)
        ->and($totals['source_hours'])->toBe(6.5)
        ->and($totals['attendance_hours'])->toBe(22.5);
});

it('approves several rows in one call', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);
    $ids = collect(['2026-10-01', '2026-10-02', '2026-10-03'])
        ->map(fn (string $date) => otQueueRow($employee, $date, 2.0)->id)
        ->all();

    $result = app(RosterOvertimeService::class)->bulkApprove($ids, $this->user->id);

    expect($result['approved'])->toBe(3)
        ->and($result['skipped'])->toBe([])
        ->and(RosterOvertime::query()->whereIn('id', $ids)->where('status', 'Approved')->count())->toBe(3)
        ->and(RosterOvertime::query()->whereIn('id', $ids)->whereNotNull('approved_by')->count())->toBe(3);
});

it('reports a refused row instead of aborting the batch', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);
    $good = otQueueRow($employee, '2026-10-01', 2.0);
    $orphaned = otQueueRow($employee, '2026-10-02', 2.0);

    // The approval refuses a row whose source attendance day has disappeared, so remove it here —
    // exactly what a re-sync or a manual correction can do between page load and button press.
    DB::table('attendance_dailies')->where('employee_id', $employee->employee_id)
        ->where('duty_date', '2026-10-02 00:00:00')->delete();

    $result = app(RosterOvertimeService::class)->bulkApprove([$good->id, $orphaned->id], $this->user->id);

    expect($result['approved'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['id'])->toBe($orphaned->id)
        ->and($result['skipped'][0]['reason'])->not->toBe('')
        ->and($good->refresh()->status)->toBe('Approved')
        ->and($orphaned->refresh()->status)->toBe('Pending');
});

it('refuses to approve more than five hundred rows at once', function (): void {
    $this->post('/hrm/overtimes/bulk-approve', ['ids' => range(1, 501)])
        ->assertSessionHasErrors('ids');

    $this->post('/hrm/overtimes/bulk-approve', ['ids' => []])
        ->assertSessionHasErrors('ids');
});

it('ships a printable sheet covering every row of the current view', function (): void {
    $employee = Employee::factory()->create(['allow_overtime' => 1]);

    foreach (range(1, 3) as $day) {
        otQueueRow($employee, '2026-10-0'.$day, 1.5);
    }

    $sheet = app(RosterOvertimeService::class)->printableSheet(otQuery(), 'Overtime');

    expect($sheet)->toHaveCount(3)
        ->and($sheet[0])->toHaveKeys(['employee_id', 'employee', 'overtime_date', 'hours', 'source_hours', 'worked_hours', 'attendance_status'])
        ->and((float) $sheet[0]['source_hours'])->toBe(1.5);
});

it('prints the date range as one filter, not two', function (): void {
    // Read the payload the controller builds, rather than a rendered page: a plain GET returns the
    // Inertia HTML shell, which carries no props.
    $response = $this->get('/hrm/overtimes');

    $response->assertOk();

    $defs = collect($response->viewData('page')['props']['filterDefs'] ?? []);
    $range = $defs->firstWhere('kind', 'daterange');

    expect($range)->not->toBeNull()
        ->and($range['fromKey'])->toBe('date_from')
        ->and($range['toKey'])->toBe('date_to')
        // The two halves must not also be offered as standalone boxes.
        ->and($defs->whereIn('key', ['date_from', 'date_to']))->toHaveCount(0);
});

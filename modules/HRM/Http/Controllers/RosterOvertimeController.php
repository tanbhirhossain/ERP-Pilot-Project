<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\ApprovalEngine;
use Modules\HRM\Services\RosterOvertimeService;

final class RosterOvertimeController extends Controller
{
    public function __construct(private readonly RosterOvertimeService $service) {}

    public function index(Request $request): Response
    {
        return $this->queue($request, 'Overtime');
    }

    public function extraDutyIndex(Request $request): Response
    {
        return $this->queue($request, 'Extra Duty');
    }

    public function syncFromAttendance(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->can('hrm.overtimes.approve'), 403);
        $data = $request->validate(['kind' => ['required', 'in:Overtime,Extra Duty']]);
        $result = $this->service->syncFromAttendance($data['kind']);

        return back()->with('success', $result['created'].' attendance approval request(s) created; '.$result['skipped'].' existing or ineligible day(s) skipped.');
    }

    private function queue(Request $request, string $kind): Response
    {
        // Refresh recent attendance-derived rows on entry; the manual sync endpoint remains available for older backfills.
        if ($request->user()?->can('hrm.overtimes.approve')) {
            $this->service->syncFromAttendance($kind, Carbon::now()->subMonths(3)->startOfMonth()->toDateString(), Carbon::now()->subDay()->toDateString());
        }
        $dt = DataTableQuery::fromRequest(
            $request,
            ['id', 'overtime_date', 'hours', 'created_at'],
            ['employee_id', 'department_id', 'designation_id', 'date_from', 'date_to'],
        );
        $page = $this->service->attendanceApprovalQueue($dt, $kind);
        $rows = $page->getCollection();
        $dates = $rows->pluck('overtime_date')->map(fn ($date) => $date?->toDateString())->filter()->unique();
        $employees = $rows->pluck('employee_id')->unique();
        // duty_date is stored in BOTH shapes ('2026-10-17' and '2026-10-17 00:00:00'),
        // so a plain whereIn on the date column silently missed half the rows and the
        // queue rendered 0.00 h for every attendance-derived figure. Compare on date only.
        $dailyByKey = AttendanceDaily::query()
            ->whereIn('employee_id', $employees)
            ->whereIn(DB::raw('date(duty_date)'), $dates->all())
            ->get()
            ->keyBy(fn ($daily) => $daily->employee_id.'|'.$daily->duty_date?->toDateString());
        $mapped = $page->through(function (RosterOvertime $row) use ($dailyByKey, $kind): array {
            $date = $row->overtime_date?->toDateString();
            $daily = $dailyByKey->get($row->employee_id.'|'.$date);

            return [
                'id' => $row->id, 'employee_id' => $row->employee_id,
                'employee' => $row->employee?->full_name ?: $row->employee_id,
                'overtime_date' => $date, 'overtime_type' => $row->overtime_type,
                'shift' => $row->shift?->shift_name, 'start_time' => substr((string) $row->start_time, 0, 5),
                'end_time' => substr((string) $row->end_time, 0, 5), 'hours' => (float) $row->hours,
                'source_attendance_hours' => (float) ($kind === 'Overtime' ? ($daily?->total_overtime_hours ?? 0) : ($daily?->grand_total_hours ?? 0)),
                'attendance_overtime_hours' => (float) ($daily?->total_overtime_hours ?? 0),
                'worked_hours' => (float) ($daily?->grand_total_hours ?? 0),
                'attendance_status' => $daily?->status, 'status' => $row->status,
            ];
        });
        $isExtra = $kind === 'Extra Duty';

        return Inertia::render($isExtra ? 'HRM::ExtraDuty/Index' : 'HRM::Overtimes/Index', [
            'requests' => PaginatedResource::from($mapped),
            'queueRoute' => $isExtra ? 'hrm.extra-duty.index' : 'hrm.overtimes.index',
            'kind' => $kind,
            // Footer totals cover the whole filtered set, not the 25 rows on screen.
            'totals' => $this->service->queueTotals($dt, $kind),
            // The printable sheet is a document: every row of the current view, with the company
            // letterhead the operator would otherwise retype into Word.
            'sheet' => [
                'rows' => $this->service->printableSheet($dt, $kind),
                'company' => $this->letterhead(),
                'filters' => $this->filterSummary($dt),
            ],
            'canApprove' => (bool) (auth()->user()?->can('hrm.overtimes.approve')),
            'filterDefs' => [
                // One control, two filter keys: a date range is a single question.
                ['key' => 'date_range', 'label' => 'Date range', 'kind' => 'daterange', 'fromKey' => 'date_from', 'toKey' => 'date_to'],
                ['key' => 'department_id', 'label' => 'Department', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/departments', 'placeholder' => 'Filter department'],
                ['key' => 'designation_id', 'label' => 'Designation', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/designations', 'placeholder' => 'Filter designation'],
                ['key' => 'employee_id', 'label' => 'Employee', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/employees', 'placeholder' => 'Search employee…'],
            ],
        ]);
    }

    /**
     * Approve several queued days at once.
     *
     * Deliberately a POST of explicit ids rather than "approve everything matching this filter": an
     * operator can always see exactly which rows they ticked, and the queue can grow between the moment
     * the filter was applied and the moment the button is pressed. Each id still goes through the same
     * single-row approval — same transaction, same payroll cutlist — so bulk is a convenience, not a
     * second, laxer route to money.
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('hrm.overtimes.approve'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->service->bulkApprove($data['ids'], (int) $request->user()->id, $data['comments'] ?? null);

        if ($result['approved'] === 0) {
            return back()->with('error', 'None of the '.count($data['ids']).' selected record(s) could be approved. '.$this->skipSummary($result['skipped']));
        }

        $message = $result['approved'].' of '.count($data['ids']).' attendance-linked request(s) approved.';

        if ($result['skipped'] !== []) {
            $message .= ' '.count($result['skipped']).' skipped — '.$this->skipSummary($result['skipped']);
        }

        return back()->with('success', $message);
    }

    /** One line naming why rows were skipped, so a partial bulk result is never a silent one. */
    private function skipSummary(array $skipped): string
    {
        if ($skipped === []) {
            return '';
        }

        return collect($skipped)
            ->groupBy('reason')
            ->map(fn ($group, $reason) => count($group).' × '.$reason)
            ->take(3)
            ->implode('; ');
    }

    /** Company block for the printable sheet's letterhead. */
    private function letterhead(): array
    {
        $org = DB::table('organizations')->orderBy('id')->first();

        return [
            'name' => $org->name ?? config('app.name', 'Company'),
            'address' => $org->address ?? '',
            'phone' => $org->phone ?? '',
            'email' => $org->email ?? '',
            'website' => $org->website ?? '',
        ];
    }

    /**
     * A human summary of the filters the sheet was printed under.
     *
     * A printed report without its parameters is unverifiable a week later; the signature block can
     * then be checked against what was actually asked for.
     */
    private function filterSummary(DataTableQuery $dt): array
    {
        $labels = [
            'date_from' => 'From',
            'date_to' => 'To',
            'department_id' => 'Department',
            'designation_id' => 'Designation',
            'employee_id' => 'Employee',
        ];

        $summary = [];

        foreach ($dt->filters as $key => $value) {
            if (! isset($labels[$key])) {
                continue;
            }

            $summary[] = ['label' => $labels[$key], 'value' => $this->filterDisplayValue($key, (string) $value)];
        }

        if ($dt->search !== null && trim($dt->search) !== '') {
            $summary[] = ['label' => 'Search', 'value' => trim($dt->search)];
        }

        return $summary;
    }

    /** Turns an id-shaped filter value into the name printed on the sheet. */
    private function filterDisplayValue(string $key, string $value): string
    {
        return match ($key) {
            'department_id' => (string) (DB::table('departments')->where('id', $value)->value('name') ?? $value),
            'designation_id' => (string) (DB::table('designations')->where('id', $value)->value('name') ?? $value),
            'employee_id' => (string) (DB::table('employees')->where('employee_id', $value)->value('full_name') ?? $value),
            default => $value,
        };
    }

    public function show(RosterOvertime $overtime): Response
    {
        $o = $this->service->findOrFail($overtime->id);
        $daily = AttendanceDaily::query()->where('employee_id', $o->employee_id)->whereDate('duty_date', $o->overtime_date)->first();
        $sourceHours = $o->overtime_type === 'Overtime'
            ? (float) ($daily?->total_overtime_hours ?? 0)
            : (float) ($daily?->grand_total_hours ?? 0);

        return Inertia::render('HRM::Overtimes/Show', ['overtime' => [
            'id' => $o->id, 'employee_id' => $o->employee_id, 'employee' => $o->employee?->full_name,
            'overtime_date' => $o->overtime_date?->toDateString(), 'overtime_type' => $o->overtime_type,
            'shift' => $o->shift?->shift_name, 'start_time' => $o->start_time, 'end_time' => $o->end_time,
            'hours' => (float) $o->hours, 'source_attendance_hours' => $sourceHours,
            'reason' => $o->reason, 'status' => $o->status,
            'approver' => $o->approver?->name, 'approved_at' => $o->approved_at?->toIso8601String(),
        ], 'canApprove' => (bool) auth()->user()?->can('hrm.overtimes.approve')]);
    }

    public function approve(Request $request, RosterOvertime $overtime): RedirectResponse
    {
        abort_unless($request->user()?->can('hrm.overtimes.approve'), 403);
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($denial = $this->stageDenial($request, $overtime)) {
            return back()->with('error', $denial);
        }
        $result = $this->service->approve($overtime, (float) $data['hours'], (int) $request->user()->id, $data['comments'] ?? null);

        if ($result->status !== 'Pending') {
            return back()->with('success', 'Attendance-linked request approved with the reviewed hours; it will enter payroll cutlists generated after approval.');
        }

        $stage = app(ApprovalEngine::class)->overtimeStage($result);

        return back()->with('success', 'Approval stage recorded. The record is now waiting on '.($stage['label'] ?? 'the next stage').'.');
    }

    public function reject(Request $request, RosterOvertime $overtime): RedirectResponse
    {
        abort_unless($request->user()?->can('hrm.overtimes.approve'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($denial = $this->stageDenial($request, $overtime)) {
            return back()->with('error', $denial);
        }
        $this->service->reject($overtime, (int) $request->user()->id, $data['reason']);

        return back()->with('success', 'Attendance-linked request rejected with an audit reason.');
    }

    /**
     * Under the overtime chain only the current stage's desk may decide; the
     * denial names the desk the record actually waits on. Without a chain the
     * permission gate above stays the only check.
     *
     * @return ?string the denial message, or null when the user may decide
     */
    private function stageDenial(Request $request, RosterOvertime $overtime): ?string
    {
        $engine = app(ApprovalEngine::class);

        if ($engine->steps(ApprovalChain::OVERTIME) === null) {
            return null;
        }

        [$ok, $denied] = $engine->canApproveOvertime($request->user(), $overtime);

        return $ok ? null : ($denied ?? 'This record is waiting on another desk.');
    }
}

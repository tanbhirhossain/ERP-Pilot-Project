<?php
namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\RosterOvertimeService;

final class RosterOvertimeController extends Controller
{
    public function __construct(private readonly RosterOvertimeService $service) {}

    public function index(Request $request): Response { return $this->queue($request, 'Overtime'); }
    public function extraDutyIndex(Request $request): Response { return $this->queue($request, 'Extra Duty'); }

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
        $dt = DataTableQuery::fromRequest($request, ['id', 'overtime_date', 'hours', 'created_at'], ['employee_id', 'department_id', 'designation_id']);
        $page = $this->service->attendanceApprovalQueue($dt, $kind);
        $rows = $page->getCollection();
        $dates = $rows->pluck('overtime_date')->map(fn ($date) => $date?->toDateString())->filter()->unique();
        $employees = $rows->pluck('employee_id')->unique();
        $dailyByKey = AttendanceDaily::query()->whereIn('employee_id', $employees)->whereIn('duty_date', $dates)->get()->keyBy(fn ($daily) => $daily->employee_id.'|'.$daily->duty_date?->toDateString());
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
            'canApprove' => (bool) (auth()->user()?->can('hrm.overtimes.approve')),
            'filterDefs' => [
                ['key' => 'department_id', 'label' => 'Department', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/departments', 'placeholder' => 'Filter department'],
                ['key' => 'designation_id', 'label' => 'Designation', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/designations', 'placeholder' => 'Filter designation'],
                ['key' => 'employee_id', 'label' => 'Employee', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/employees', 'placeholder' => 'Search employee…'],
            ],
        ]);
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
        $this->service->approve($overtime, (float) $data['hours'], (int) $request->user()->id, $data['comments'] ?? null);
        return back()->with('success', 'Attendance-linked request approved with the reviewed hours; it will enter payroll cutlists generated after approval.');
    }

    public function reject(Request $request, RosterOvertime $overtime): RedirectResponse
    {
        abort_unless($request->user()?->can('hrm.overtimes.approve'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->service->reject($overtime, (int) $request->user()->id, $data['reason']);
        return back()->with('success', 'Attendance-linked request rejected with an audit reason.');
    }

}

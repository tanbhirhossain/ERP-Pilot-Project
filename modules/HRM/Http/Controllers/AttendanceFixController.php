<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Jobs\FixAttendancePairingJob;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\Attendance\AttendanceRepairService;

class AttendanceFixController extends Controller
{
    public function __construct(private readonly AttendanceRepairService $repair) {}

    public function index(Request $r): Response
    {
        $departments = Department::orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->values();

        return Inertia::render('HRM::Attendance/Fix/Index', [
            'departmentOptions' => $departments,
            'initialFilters' => [
                'scope' => $r->query('employee_id') ? 'employee' : ($r->query('department_id') ? 'department' : 'all'),
                'employee_id' => $r->query('employee_id'),
                'department_id' => $r->query('department_id') ? (int) $r->query('department_id') : null,
                'from' => $r->query('from'), 'to' => $r->query('to'),
            ],
            // stats for header
            'stats' => [
                'total_pairs' => AttendancePair::count(),
                'total_dailies' => AttendanceDaily::count(),
                'total_logs' => AttendanceLog::count(),
                'pending_logs' => AttendanceLog::where('is_processed', false)->count(),
            ],
            'lastRepairableDate' => $this->repair->lastRepairableDate()->toDateString(),
            'fixStatus' => Cache::get(AttendanceRepairService::STATUS_KEY) ?? ['state' => 'idle'],
        ]);
    }

    /**
     * What the queue is doing right now, for the page's poll. Kept tiny and unauthenticated-by-permission
     * beyond `hrm.attendance.view` (the route middleware) because it only exposes counters.
     */
    public function status(): JsonResponse
    {
        return response()->json(Cache::get(AttendanceRepairService::STATUS_KEY) ?? ['state' => 'idle']);
    }

    public function fix(Request $r)
    {
        $data = $r->validate([
            'scope' => ['required', 'in:all,department,employee'],
            'department_id' => ['required_if:scope,department', 'nullable', 'exists:departments,id'],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'string', 'exists:employees,employee_id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'dry_run' => ['required', 'boolean'],
        ]);
        $from = $data['from'];
        $to = $data['to'];
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
            return back()->with('error', 'Choose a repair range of 32 days or less. Run larger repairs in smaller date ranges.');
        }

        $scope = $data['scope'];
        $employeeIds = null;
        if ($scope === 'employee') {
            $employeeIds = [$data['employee_id']];
        } elseif ($scope === 'department') {
            $employeeIds = Employee::where('department_id', $data['department_id'])->pluck('employee_id')->all();
            if (! $employeeIds) {
                return back()->with('error', 'No employees in the selected department.');
            }
        }
        $dry = (bool) $data['dry_run'];

        $q = AttendancePair::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereDate('duty_date', '>=', $from)
            ->whereDate('duty_date', '<=', $to);
        $wrong = (clone $q)->where(function ($query): void {
            $query->whereRaw('worked_hours > 12')
                ->orWhereRaw('DATE(punch_in) != duty_date')
                ->orWhereRaw('DATE(punch_out) != duty_date');
        })->get();

        $dailyQ = AttendanceDaily::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereDate('duty_date', '>=', $from)->whereDate('duty_date', '<=', $to);
        $deviceUserIds = $employeeIds === null ? [] : DeviceUser::query()->whereIn('employee_id', $employeeIds)->pluck('device_user_id')->map(fn ($id) => (string) $id)->all();
        if ($employeeIds !== null) {
            $deviceUserIds = array_values(array_unique(array_merge($deviceUserIds, Employee::query()->whereIn('employee_id', $employeeIds)->whereNotNull('biometric_id')->pluck('biometric_id')->map(fn ($id) => (string) $id)->all())));
        }
        $logQ = AttendanceLog::query()
            ->when($employeeIds !== null, function ($query) use ($employeeIds, $deviceUserIds): void {
                $query->where(function ($scope) use ($employeeIds, $deviceUserIds): void {
                    $scope->whereIn('employee_id', $employeeIds);
                    if ($deviceUserIds) {
                        $scope->orWhereIn('device_user_id', $deviceUserIds);
                    }
                });
            })
            ->where('punch_time', '>=', $from.' 00:00:00')
            ->where('punch_time', '<=', $to.' 23:59:59');
        $dailyCount = (clone $dailyQ)->count();
        $logCount = (clone $logQ)->count();
        $logProcessed = (clone $logQ)->where('is_processed', true)->count();
        // Rows with no punches at all (Off Day / Holiday / On Leave) plus regularized days are the ones a
        // rebuild cannot resurrect from logs. Count them so the dry run can promise — and the repair report —
        // that they are reclassified through Stage 3, and restored if the calendar cannot reproduce them.
        $rosterDerived = (clone $dailyQ)->whereIn('status', ['Off Day', 'Holiday', 'On Leave'])->count();
        $preview = [
            'roster_derived_rows' => $rosterDerived,
            'wrong_pairs' => $wrong->map(fn ($pair) => [
                'id' => $pair->id, 'employee_id' => $pair->employee_id,
                'duty_date' => $pair->duty_date?->toDateString(),
                'punch_in' => $pair->punch_in?->toIso8601String(),
                'punch_out' => $pair->punch_out?->toIso8601String(),
                'hours' => $pair->worked_hours, 'type' => $pair->shift_type_applied,
            ])->take(50)->values(),
            'wrong_count' => $wrong->count(), 'daily_count' => $dailyCount,
            'log_count' => $logCount, 'log_processed' => $logProcessed,
            'scope' => $scope, 'employee_ids' => $employeeIds, 'from' => $from, 'to' => $to,
            // What the queued repair will actually be able to finalise: the service clamps the range to
            // yesterday, so say so here rather than promising days it will refuse to touch.
            'last_repairable_date' => $this->repair->lastRepairableDate()->toDateString(),
            'clipped' => Carbon::parse($to)->gt($this->repair->lastRepairableDate()),
        ];

        if ($dry) {
            return back()->with('success', "DRY RUN: {$wrong->count()} suspect pairs, {$dailyCount} daily rows ({$rosterDerived} of them Off Day/Holiday/On Leave rows that the rebuild reclassifies from the roster, not from punches), and {$logCount} logs (of which {$logProcessed} are processed). No data changed.".($preview['clipped'] ? " The repair only finalises days up to {$preview['last_repairable_date']}; anything later stays with the nightly pipeline." : ''))->with('preview', $preview);
        }
        $globalPending = AttendanceLog::query()->where('is_processed', false)->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')->count();
        $reprocessLimitCount = $globalPending + $logProcessed;
        if ($logCount > 50000 || $reprocessLimitCount > 50000) {
            return back()->with('error', 'This repair would process more than 50,000 raw logs (including other pending logs in the date range). Narrow the date range before applying.');
        }

        // One repair at a time: it clears a range and rebuilds it, so a second concurrent run would fight
        // the first for the same rows (the job is also ShouldBeUnique, but that fails silently).
        $running = Cache::get(AttendanceRepairService::STATUS_KEY);

        if (($running['state'] ?? null) === 'running') {
            return back()->with('error', 'A repair is already running ('.($running['scope'] ?? 'unknown scope').', '.($running['from'] ?? '?').' → '.($running['to'] ?? '?').', step: '.($running['step'] ?? '?').'). Wait for it to finish before queueing another.');
        }

        FixAttendancePairingJob::dispatch((string) $from, (string) $to, $employeeIds, $scope === 'employee' ? 'employee '.$data['employee_id'] : ($scope === 'department' ? 'department '.$data['department_id'] : 'all employees'), $r->user()?->id);

        $scopeLabel = $scope === 'employee' ? "employee {$data['employee_id']}" : ($scope === 'department' ? 'the selected department' : 'all employees');

        return back()->with('success', "Repair queued for {$scopeLabel}, {$from} through {$to}. It runs on the queue worker — this page shows its progress and the full report when it lands; you can navigate away. ".($preview['clipped'] ? "Note: the repair only finalises days up to {$preview['last_repairable_date']}; today and later days belong to the nightly pipeline." : ''))
            ->with('preview', $preview);
    }
}

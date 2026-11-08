<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

class AttendanceFixController extends Controller
{
    public function __construct(private readonly AttendanceProcessingService $processor) {}

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
        ]);
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
        $preview = [
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
        ];

        if ($dry) {
            return back()->with('success', "DRY RUN: {$wrong->count()} suspect pairs, {$dailyCount} daily rows, and {$logCount} logs (of which {$logProcessed} are processed). No data changed.")->with('preview', $preview);
        }
        $globalPending = AttendanceLog::query()->where('is_processed', false)->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')->count();
        $reprocessLimitCount = $globalPending + $logProcessed;
        if ($logCount > 50000 || $reprocessLimitCount > 50000) {
            return back()->with('error', 'This repair would process more than 50,000 raw logs (including other pending logs in the date range). Narrow the date range before applying.');
        }

        // A repair is a clean scoped rebuild, not just deleting suspect pairs while leaving
        // previously processed logs and dailies out of sync.
        DB::transaction(function () use ($q, $dailyQ, $logQ): void {
            (clone $q)->delete();
            (clone $dailyQ)->delete();
            (clone $logQ)->update(['is_processed' => false]);
        });

        $res = $this->processor->processPending(50000, $from.' 00:00:00', $to.' 23:59:59');
        $newPairs = AttendancePair::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereDate('duty_date', '>=', $from)->whereDate('duty_date', '<=', $to)
            ->orderBy('duty_date')->limit(50)->get()
            ->map(fn ($pair) => ['duty_date' => $pair->duty_date?->toDateString(), 'punch_in' => $pair->punch_in?->format('d M H:i'), 'punch_out' => $pair->punch_out?->format('d M H:i'), 'hours' => $pair->worked_hours, 'type' => $pair->duty_type]);

        return back()->with('success', "Rebuilt the selected scope from {$from} through {$to}: cleared {$preview['daily_count']} daily rows and reset {$logCount} logs. Reprocessed {$res['processed']} logs.")
            ->with('result', ['preview' => $preview, 'reprocess' => $res, 'new_pairs' => $newPairs]);
    }
}

<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveWorkspaceService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesTeamScope;

/**
 * Team visibility + on-behalf leave (Phase 3).
 *
 * Supervisors see their direct reports; HR/DGM/GM/CEO/Admins see everyone
 * (ResolvesTeamScope). Every employee_id parameter is scope-checked: outside
 * the caller's scope answers 403, never a leak.
 *
 * GET  /api/v1/team/members                                   my team
 * GET  /api/v1/team/attendance/logs|pairs|daily|summary      ?employee_id=&from=&to=
 * GET  /api/v1/team/leaves?employee_id=&status=              reports' applications
 * POST /api/v1/team/leaves/apply                             file leave FOR a report
 * GET  /api/v1/team/overtimes?employee_id=&status=           reports' OT/ED
 * GET  /api/v1/team/roster?employee_id=&from=&to=            reports' duties
 *
 * Approving itself stays on /approvals/* (the engine is supervisor-aware).
 */
class TeamApiController extends Controller
{
    use ResolvesTeamScope;

    public function __construct(
        private readonly LeaveRequestService $leaves,
        private readonly LeaveWorkspaceService $workspace,
    ) {}

    public function members(Request $request): JsonResponse
    {
        $codes = $this->teamCodes($request->user());

        $query = Employee::with(['department:id,name', 'designation:id,name'])
            ->orderBy('full_name')->orderBy('first_name');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        $members = $query->get([
            'employee_id', 'full_name', 'first_name', 'last_name', 'department_id',
            'designation_id', 'status', 'employment_type', 'joining_date', 'photo',
        ])->map(fn (Employee $e): array => [
            'employee_id' => $e->employee_id,
            'full_name' => $e->full_name ?? trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
            'department' => $e->department?->name,
            'designation' => $e->designation?->name,
            'status' => $e->status,
            'employment_type' => $e->employment_type,
            'joining_date' => $e->joining_date?->toDateString(),
            'photo' => $e->photo,
        ]);

        return ApiResponse::ok($members->values()->all(), 'Team members.', ['total' => $members->count()]);
    }

    public function attendanceLogs(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = AttendanceLog::where('employee_id', $code)
            ->whereBetween('punch_time', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderByDesc('punch_time')
            ->paginate($perPage);

        return ApiResponse::paginated($page, "Attendance log for {$code}.");
    }

    public function attendancePairs(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = AttendancePair::where('employee_id', $code)
            ->whereBetween('duty_date', [$from, $to])
            ->orderByDesc('duty_date')->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::paginated($page, "Attendance pairs for {$code}.");
    }

    public function attendanceDaily(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = AttendanceDaily::where('employee_id', $code)
            ->whereBetween('duty_date', [$from, $to])
            ->orderByDesc('duty_date')
            ->paginate($perPage);

        return ApiResponse::paginated($page, "Daily attendance for {$code}.");
    }

    public function attendanceSummary(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now()->format('Y-m');

        $days = AttendanceDaily::where('employee_id', $code)
            ->whereBetween('duty_date', [$month.'-01', date('Y-m-t', strtotime($month.'-01'))])
            ->get();

        return ApiResponse::ok([
            'employee_id' => $code,
            'month' => $month,
            'days' => $days->count(),
            'present' => $days->whereIn('status', ['Present', 'Late', 'Early Out', 'Late & Early Out'])->count(),
            'late' => $days->whereIn('status', ['Late', 'Late & Early Out'])->count(),
            'absent' => $days->where('status', 'Absent')->count(),
            'on_leave' => $days->where('status', 'On Leave')->count(),
            'missing_punch' => $days->whereIn('status', ['Missing In', 'Missing Out'])->count(),
            'worked_hours' => round((float) $days->sum('primary_worked_hours'), 2),
            'overtime_hours' => round((float) $days->sum('total_overtime_hours'), 2),
        ], "Attendance summary for {$code}.");
    }

    public function leaves(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'exists:employees,employee_id'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $codes = $this->teamCodes($request->user());

        if (! empty($data['employee_id'])) {
            if ($codes !== null && ! in_array($data['employee_id'], $codes, true)) {
                return ApiResponse::error('This employee is outside your team.', 403);
            }

            $codes = [$data['employee_id']];
        }

        $query = LeaveRequest::with(['employee:employee_id,full_name,first_name,last_name', 'leaveType:id,name,code,color'])
            ->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $page = $query->paginate($data['per_page'] ?? 15)->through(
            fn (LeaveRequest $leave): array => $this->workspace->requestRow($leave)
        );

        return ApiResponse::paginated($page, 'Team leave applications.');
    }

    public function applyLeave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'is_half_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->inScope($request->user(), $data['employee_id'])) {
            return ApiResponse::error('This employee is outside your team.', 403);
        }

        try {
            $leave = $this->leaves->create($data);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException|\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $leave->load(['employee:employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color']);

        return ApiResponse::ok($this->workspace->requestRow($leave), "Leave filed for {$data['employee_id']}.", [], 201);
    }

    public function overtimes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'exists:employees,employee_id'],
            'status' => ['nullable', 'string', 'max:20'],
            'overtime_type' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $codes = $this->teamCodes($request->user());

        if (! empty($data['employee_id'])) {
            if ($codes !== null && ! in_array($data['employee_id'], $codes, true)) {
                return ApiResponse::error('This employee is outside your team.', 403);
            }

            $codes = [$data['employee_id']];
        }

        $query = RosterOvertime::with(['employee:employee_id,full_name,first_name,last_name', 'approver:id,name'])
            ->orderByDesc('overtime_date')->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['overtime_type'])) {
            $query->where('overtime_type', $data['overtime_type']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'Team overtime records.');
    }

    public function roster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'exists:employees,employee_id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $codes = $this->teamCodes($request->user());

        if (! empty($data['employee_id'])) {
            if ($codes !== null && ! in_array($data['employee_id'], $codes, true)) {
                return ApiResponse::error('This employee is outside your team.', 403);
            }

            $codes = [$data['employee_id']];
        }

        $query = RosterDuty::with(['employee:employee_id,full_name,first_name,last_name', 'shift:id,shift_name,start_time,end_time'])
            ->orderBy('roster_date')->orderBy('employee_id');

        if ($codes !== null) {
            if ($codes === []) {
                return ApiResponse::ok([], 'Team roster duties.', ['total' => 0]);
            }

            $query->whereIn('employee_id', $codes);
        }

        if (! empty($data['from'])) {
            $query->whereDate('roster_date', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('roster_date', '<=', $data['to']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 50), 'Team roster duties.');
    }

    /**
     * Required + scope-checked employee for the single-report endpoints.
     *
     * @return array{0:?string,1:?JsonResponse}
     */
    private function scopedCode(Request $request): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
        ]);

        if (! $this->inScope($request->user(), $data['employee_id'])) {
            return [null, ApiResponse::error('This employee is outside your team.', 403)];
        }

        return [$data['employee_id'], null];
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return [
            $data['from'] ?? now()->startOfMonth()->toDateString(),
            $data['to'] ?? now()->endOfMonth()->toDateString(),
            $data['per_page'] ?? 50,
        ];
    }
}

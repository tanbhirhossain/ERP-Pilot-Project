<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
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
 * POST /api/v1/team/overtimes/claim                          file OT/ED FOR a report
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
        private readonly EmployeeRepositoryInterface $employees,
        private readonly AttendanceLogRepositoryInterface $logs,
        private readonly AttendancePairRepositoryInterface $pairs,
        private readonly AttendanceDailyRepositoryInterface $dailies,
        private readonly LeaveRequestRepositoryInterface $leaveRows,
        private readonly RosterOvertimeRepositoryInterface $overtimeRows,
        private readonly RosterDutyRepositoryInterface $dutyRows,
    ) {}

    #[ApiEndpoint(
        summary: 'My team',
        description: 'Direct reports for a supervisor; everyone for HR, DGM, GM, CEO and Admins.',
        response: 'MobileEnvelope',
    )]
    public function members(Request $request): JsonResponse
    {
        $codes = $this->teamCodes($request->user());

        $members = $this->employees->teamMembers($codes)
            ->map(fn ($e): array => [
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

    #[ApiEndpoint(
        summary: 'A report\'s punch log',
        description: 'Outside the caller\'s scope answers 403, never a leak.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Report code, e.g. EMP-001.'],
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function attendanceLogs(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->logs->paginateForEmployee($code, $from, $to, $perPage);

        return ApiResponse::paginated($page, "Attendance log for {$code}.");
    }

    #[ApiEndpoint(
        summary: 'A report\'s IN/OUT pairs',
        description: 'Outside the caller\'s scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function attendancePairs(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->pairs->paginateForEmployee($code, $from, $to, $perPage);

        return ApiResponse::paginated($page, "Attendance pairs for {$code}.");
    }

    #[ApiEndpoint(
        summary: 'A report\'s daily verdicts',
        description: 'Outside the caller\'s scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function attendanceDaily(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->dailies->paginateForEmployee($code, $from, $to, $perPage);

        return ApiResponse::paginated($page, "Daily attendance for {$code}.");
    }

    #[ApiEndpoint(
        summary: 'A report\'s monthly summary',
        description: 'Counts by verdict plus worked/overtime hours. Outside the caller\'s scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'month', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$'], 'description' => 'YYYY-MM, defaults to this month.'],
        ],
        response: 'MobileEnvelope',
    )]
    public function attendanceSummary(Request $request): JsonResponse
    {
        [$code, $error] = $this->scopedCode($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now()->format('Y-m');

        $days = $this->dailies->listForEmployeeRange($code, $month.'-01', date('Y-m-t', strtotime($month.'-01')));

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

    #[ApiEndpoint(
        summary: 'Reports\' leave applications',
        description: 'Scope-filtered register; pass employee_id for one report. Outside scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
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

        $page = $this->leaveRows->paginateForCodes($codes, $data['status'] ?? null, $data['per_page'] ?? 15)->through(
            fn ($leave): array => $this->workspace->requestRow($leave)
        );

        return ApiResponse::paginated($page, 'Team leave applications.');
    }

    #[ApiEndpoint(
        summary: 'File leave for a report',
        description: 'On-behalf filing through the same service as self-apply, chain snapshot included. Outside scope answers 403.',
        request: 'MobileTeamLeaveApply',
        response: 'MobileEnvelope',
    )]
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

        $leave = $this->leaveRows->findDetailed($leave->id) ?? $leave;

        return ApiResponse::ok($this->workspace->requestRow($leave), "Leave filed for {$data['employee_id']}.", [], 201);
    }

    #[ApiEndpoint(
        summary: 'Reports\' overtime records',
        description: 'Scope-filtered register. Outside scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'overtime_type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['Overtime', 'Extra Duty', 'Holiday Duty']]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
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

        $page = $this->overtimeRows->paginateForCodes(
            $codes,
            $data['status'] ?? null,
            $data['overtime_type'] ?? null,
            $data['per_page'] ?? 20,
        );

        return ApiResponse::paginated($page, 'Team overtime records.');
    }

    #[ApiEndpoint(
        summary: 'Claim overtime for a report',
        description: 'On-behalf OT/Extra-Duty/Holiday claim through the same checks as self-claim (hours or start/end, duplicate date+type refused) plus the first-stage ping. Outside scope answers 403.',
        request: 'MobileTeamOvertimeClaim',
        response: 'MobileEnvelope',
    )]
    public function claimOvertime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'overtime_date' => ['required', 'date', 'before_or_equal:today'],
            'overtime_type' => ['required', Rule::in(OvertimeApiController::TYPES)],
            'shift_id' => ['nullable', 'integer', 'exists:roster_shifts,id'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->inScope($request->user(), $data['employee_id'])) {
            return ApiResponse::error('This employee is outside your team.', 403);
        }

        if (! empty($data['start_time']) && ! empty($data['end_time'])) {
            $start = Carbon::createFromFormat('H:i', $data['start_time']);
            $end = Carbon::createFromFormat('H:i', $data['end_time']);

            if ($end->lessThanOrEqualTo($start)) {
                return ApiResponse::error('End time must be after start time.', 422);
            }

            $data['hours'] = $data['hours'] ?? round($start->floatDiffInHours($end), 2);
        }

        if (empty($data['hours']) && empty($data['start_time'])) {
            return ApiResponse::error('Give either the hours or the start/end time.', 422);
        }

        if ($this->overtimeRows->duplicateExists($data['employee_id'], $data['overtime_date'], $data['overtime_type'])) {
            return ApiResponse::error('A pending/approved record already exists for this date and type.', 422);
        }

        $ot = $this->overtimeRows->create($data + ['status' => 'Pending']);

        $ot = $this->overtimeRows->findForEmployee($ot->id, $data['employee_id'], true) ?? $ot;

        // Same first-stage ping a self-claim sends.
        app(\Modules\HRM\Services\ApprovalEngine::class)->notifyNewOvertime($ot);

        return ApiResponse::ok($ot, ucfirst(strtolower($data['overtime_type']))." claim filed for {$data['employee_id']}.", [], 201);
    }

    #[ApiEndpoint(
        summary: 'Reports\' roster duties',
        description: 'Scope-filtered duty rows in roster-date order. Outside scope answers 403.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
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

        if ($codes === []) {
            return ApiResponse::ok([], 'Team roster duties.', ['total' => 0]);
        }

        $page = $this->dutyRows->paginateForCodes(
            $codes,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $data['per_page'] ?? 50,
        );

        return ApiResponse::paginated($page, 'Team roster duties.');
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

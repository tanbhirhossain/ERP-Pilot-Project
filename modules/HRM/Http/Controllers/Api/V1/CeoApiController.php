<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Interfaces\Approval\LeaveRequestWorkflowRepositoryInterface;
use Modules\HRM\Interfaces\Approval\OvertimeRequestWorkflowRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeResignationRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveTypeRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Services\ApprovalEngine;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSalarySheetRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PfAccountRepositoryInterface;

/**
 * CEO cockpit (Phase 3): dashboard, statistics, monthly report.
 *
 * Gated to the CEO and Super Admin roles — nobody else may open these
 * endpoints. Everything is read-only aggregates over data the app already
 * owns; nothing here writes.
 *
 * GET /api/v1/ceo/dashboard              today + month at a glance
 * GET /api/v1/ceo/statistics?months=6    monthly trend series
 * GET /api/v1/ceo/report?month=YYYY-MM   consolidated monthly HR report
 */
class CeoApiController extends Controller
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly EmployeeResignationRepositoryInterface $resignations,
        private readonly AttendanceDailyRepositoryInterface $dailies,
        private readonly LeaveRequestRepositoryInterface $leaveRows,
        private readonly LeaveTypeRepositoryInterface $leaveTypes,
        private readonly RosterOvertimeRepositoryInterface $overtimeRows,
        private readonly LeaveRequestWorkflowRepositoryInterface $leaveWorkflows,
        private readonly OvertimeRequestWorkflowRepositoryInterface $overtimeWorkflows,
        private readonly PayrollSalarySheetRepositoryInterface $sheets,
        private readonly PfAccountRepositoryInterface $pfAccounts,
    ) {}

    private function gate($user): ?JsonResponse
    {
        if (! $user->hasAnyRole(['CEO', 'Super Admin'])) {
            return ApiResponse::error('The CEO cockpit needs the CEO role.', 403);
        }

        return null;
    }

    #[ApiEndpoint(
        summary: 'CEO dashboard',
        description: 'Headcount, today\'s attendance, who is on leave, approvals by stage, month overtime, latest payroll and PF totals. CEO role only.',
        response: 'MobileEnvelope',
    )]
    public function dashboard(Request $request): JsonResponse
    {
        if ($denied = $this->gate($request->user())) {
            return $denied;
        }

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $headcount = $this->employees->countActive();
        $onProbation = $this->employees->countActiveByEmploymentType('Probation');
        $joinedMonth = $this->employees->countJoinedBetween($monthStart, $monthEnd);
        $exitsMonth = $this->resignations->countApprovedBetween($monthStart, $monthEnd);

        $todayRows = $this->dailies->statusCountsForDate($today);

        $onLeaveList = $this->leaveRows->onLeaveOn($today)
            ->map(fn ($l): array => [
                'employee_id' => $l->employee_id,
                'name' => $l->employee?->full_name ?? trim(($l->employee?->first_name ?? '').' '.($l->employee?->last_name ?? '')),
                'leave_type' => $l->leaveType?->name,
                'to_date' => $l->to_date?->toDateString(),
            ]);

        $leavesPending = $this->leaveRows->countPending();
        $otPending = $this->overtimeRows->countPending();
        $myDesk = $this->engine->queueFor($request->user());

        $otApprovedMonth = $this->overtimeRows->approvedHoursBetween($monthStart, $monthEnd);

        $sheet = $this->sheets->latestReleased();
        $pfTotals = $this->pfAccounts->totals();

        return ApiResponse::ok([
            'date' => $today,
            'headcount' => [
                'active' => $headcount,
                'on_probation' => $onProbation,
                'joined_this_month' => $joinedMonth,
                'exits_this_month' => $exitsMonth,
            ],
            'today' => [
                'present' => (int) ($todayRows['Present'] ?? 0),
                'late' => (int) ($todayRows['Late'] ?? 0),
                'absent' => (int) ($todayRows['Absent'] ?? 0),
                'on_leave' => (int) ($todayRows['On Leave'] ?? 0),
                'missing_punch' => (int) (($todayRows['Missing In'] ?? 0) + ($todayRows['Missing Out'] ?? 0)),
                'recorded' => (int) $todayRows->sum(),
            ],
            'on_leave_today' => ['count' => $this->leaveRows->countOnLeaveOn($today), 'list' => $onLeaveList->all()],
            'approvals' => [
                'leaves_pending' => $leavesPending,
                'leaves_by_stage' => $this->pendingLeaveStages(),
                'overtimes_pending' => $otPending,
                'overtimes_by_stage' => $this->pendingOtStages(),
                'my_desk' => [
                    'leaves' => count($myDesk['leaves']),
                    'overtimes' => count($myDesk['overtimes']),
                ],
            ],
            'overtime_month_hours' => round($otApprovedMonth, 2),
            'payroll' => $sheet !== null ? [
                'pay_year' => $sheet->pay_year,
                'pay_month' => $sheet->pay_month,
                'employee_count' => $sheet->employee_count,
                'gross_total' => (float) $sheet->gross_total,
                'deduction_total' => (float) $sheet->deduction_total,
                'net_total' => (float) $sheet->net_total,
            ] : null,
            'provident_fund' => [
                'employee_total' => $pfTotals['employee'],
                'employer_total' => $pfTotals['employer'],
            ],
        ], 'CEO dashboard.');
    }

    #[ApiEndpoint(
        summary: 'CEO statistics',
        description: 'Monthly trend series: joined, exits, attendance rate, leave days, overtime hours. CEO role only.',
        query: [
            ['name' => 'months', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12], 'description' => 'Trailing months, default 6.'],
        ],
        response: 'MobileEnvelope',
    )]
    public function statistics(Request $request): JsonResponse
    {
        if ($denied = $this->gate($request->user())) {
            return $denied;
        }

        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:12']]);
        $months = $data['months'] ?? 6;

        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonthsNoOverflow($i)->format('Y-m');
            $from = $month.'-01';
            $to = date('Y-m-t', strtotime($from));

            $dailies = $this->dailies->statusesBetween($from, $to);
            $total = $dailies->count();
            $kept = $dailies->whereIn('status', ['Present', 'Late', 'Early Out', 'Late & Early Out', 'On Leave', 'Holiday', 'Extra Duty'])->count();

            $series[] = [
                'month' => $month,
                'joined' => $this->employees->countJoinedBetween($from, $to),
                'exits' => $this->resignations->countApprovedBetween($from, $to),
                'attendance_rate' => $total > 0 ? round(100 * $kept / $total, 1) : null,
                'leave_days' => $this->leaveRows->approvedDaysBetween($from, $to),
                'overtime_hours' => $this->overtimeRows->approvedHoursBetween($from, $to),
            ];
        }

        return ApiResponse::ok($series, 'CEO statistics.');
    }

    #[ApiEndpoint(
        summary: 'CEO monthly report',
        description: 'Attendance by verdict with top absentees/late, leave by type, overtime by department, approval aging and movement. CEO role only.',
        query: [
            ['name' => 'month', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$'], 'description' => 'YYYY-MM, defaults to this month.'],
        ],
        response: 'MobileEnvelope',
    )]
    public function report(Request $request): JsonResponse
    {
        if ($denied = $this->gate($request->user())) {
            return $denied;
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now()->format('Y-m');
        $from = $month.'-01';
        $to = date('Y-m-t', strtotime($from));

        $statusCounts = $this->dailies->statusCountsBetween($from, $to);

        $leaveByType = $this->leaveRows->approvedGroupedByType($from, $to);
        $typeNames = $this->leaveTypes->namesForIds($leaveByType->pluck('leave_type_id')->all());

        $otByDept = $this->overtimeRows->hoursByDepartmentBetween($from, $to);

        return ApiResponse::ok([
            'month' => $month,
            'attendance' => [
                'by_status' => $statusCounts->map(fn ($c): int => (int) $c)->all(),
                'top_absent' => $this->topOffenders($from, $to, ['Absent']),
                'top_late' => $this->topOffenders($from, $to, ['Late', 'Late & Early Out']),
            ],
            'leave_by_type' => $leaveByType->map(fn ($row): array => [
                'leave_type' => $typeNames[$row->leave_type_id] ?? (string) $row->leave_type_id,
                'applications' => (int) $row->n,
                'days' => round((float) $row->d, 2),
            ])->all(),
            'overtime_by_department' => $otByDept->map(fn ($row): array => [
                'department' => $row->dept,
                'records' => (int) $row->n,
                'hours' => round((float) $row->h, 2),
            ])->all(),
            'approvals_aging' => [
                'leaves_by_stage' => $this->pendingLeaveStages(),
                'leaves_by_week' => $this->pendingByWeek($this->leaveRows->pendingCreatedDates()),
                'overtimes_by_stage' => $this->pendingOtStages(),
            ],
            'movement' => [
                'joined' => $this->employees->countJoinedBetween($from, $to),
                'exits' => $this->resignations->countApprovedBetween($from, $to),
            ],
        ], 'CEO monthly report.');
    }

    /** Pending leaves grouped by the stage label they wait on (snapshot-driven). */
    private function pendingLeaveStages(): array
    {
        $ids = $this->leaveRows->pendingIds();

        if ($ids === []) {
            return [];
        }

        $counts = [];
        $workflows = $this->leaveWorkflows->stagesForLeaveIds($ids);

        foreach ($workflows as $workflow) {
            $steps = is_array($workflow->steps) ? array_values($workflow->steps) : [];
            $label = $steps[(int) $workflow->current_stage] ?? 'Unknown';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string,int> */
    private function pendingOtStages(): array
    {
        $ids = $this->overtimeRows->pendingIds();

        if ($ids === []) {
            return [];
        }

        $counts = [];
        $workflows = $this->overtimeWorkflows->stagesForOvertimeIds($ids);

        foreach ($workflows as $workflow) {
            $steps = is_array($workflow->steps) ? array_values($workflow->steps) : [];
            $label = $steps[(int) $workflow->current_stage] ?? 'Unknown';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string,int> week-start → pending count */
    private function pendingByWeek($rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $week = $row->created_at?->startOfWeek()->toDateString() ?? 'unknown';
            $buckets[$week] = ($buckets[$week] ?? 0) + 1;
        }

        ksort($buckets);

        return $buckets;
    }

    /** @return list<array{employee_id:string,name:string,days:int}> */
    private function topOffenders(string $from, string $to, array $statuses, int $limit = 10): array
    {
        return $this->dailies->topOffenders($from, $to, $statuses, $limit)
            ->map(fn ($row): array => [
                'employee_id' => $row->employee_id,
                'name' => $this->employeeName($row->employee_id),
                'days' => (int) $row->c,
            ])->all();
    }

    private function employeeName(string $code): string
    {
        $employee = $this->employees->findByEmployeeId($code);

        return $employee?->full_name ?? trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')) ?: $code;
    }
}

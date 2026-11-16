<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveApprovalAction;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;

/**
 * The read model behind the leave screens.
 *
 * Each page asks one question and this answers it in one place, so the desk, the register and the
 * reports cannot drift apart. Everything is counted in days, never in "percentage of a bar".
 */
final class LeaveWorkspaceService
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * The Leave Desk: what HR needs to act on today.
     *
     * Not a wall of KPI tiles — a queue. Who is away right now, what is waiting for a decision, and
     * what each type is carrying.
     */
    public function overview(?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $today = now()->toDateString();

        $pending = LeaveRequest::query()
            ->with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->where('status', 'Pending')
            ->orderBy('from_date')
            ->limit(50)
            ->get();

        $onLeaveNow = LeaveRequest::query()
            ->with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->where('status', 'Approved')
            ->whereDate('from_date', '<=', $today)
            ->whereDate('to_date', '>=', $today)
            ->orderBy('from_date')
            ->get();

        $typeRows = [];
        $totalEntitlement = 0.0;
        $totalApproved = 0.0;
        $longPool = 0.0;
        $totalPending = 0.0;
        foreach (LeaveType::query()->where('is_active', true)->orderBy('name')->get() as $type) {
            $approved = (float) LeaveRequest::query()
                ->where('leave_type_id', $type->id)
                ->where('status', 'Approved')
                ->whereYear('from_date', $year)
                ->sum('days');
            $pendingDays = (float) LeaveRequest::query()
                ->where('leave_type_id', $type->id)
                ->where('status', 'Pending')
                ->whereYear('from_date', $year)
                ->sum('days');
            $entitlement = round((float) Employee::query()->where('is_active', true)->count() * (float) $type->days_per_year, 2);

            $typeRows[] = [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'is_paid' => (bool) $type->is_paid,
                'days_per_year' => (float) $type->days_per_year,
                'entitlement_pool' => $entitlement,
                'approved' => round($approved, 2),
                'pending' => round($pendingDays, 2),
                'requests' => (int) LeaveRequest::query()->where('leave_type_id', $type->id)->whereYear('from_date', $year)->count(),
            ];
            $totalEntitlement += $entitlement;
            $totalApproved += $approved;
            $totalPending += $pendingDays;

            // Long-leave entitlements (maternity and the like) are most of the pool and almost none of
            // the spend. Held apart so the desk and the report quote the same two numbers instead of
            // one that silently pools a benefit map with ordinary leave.
            if ((float) $type->days_per_year >= LeaveBalanceService::LONG_LEAVE_DAYS) {
                $longPool += $entitlement;
            }
        }

        $monthly = LeaveRequest::query()
            ->where('status', 'Approved')
            ->whereYear('from_date', $year)
            ->selectRaw("strftime('%m', from_date) as m, SUM(days) as days, COUNT(*) as requests")
            ->groupBy('m')
            ->get()
            ->keyBy('m');

        $series = [];
        for ($month = 1; $month <= 12; $month++) {
            $row = $monthly->get(str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $series[] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'days' => round((float) ($row->days ?? 0), 2),
                'requests' => (int) ($row->requests ?? 0),
            ];
        }

        $lastYearClosed = HrmLeaveBalance::query()->where('year', $year - 1)->where('is_closed', true)->count();
        $thisYearClosed = HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->count();

        return [
            'year' => $year,
            'pending' => $pending->map(fn (LeaveRequest $l): array => $this->requestRow($l))->all(),
            'pending_total' => LeaveRequest::query()->where('status', 'Pending')->count(),
            'pending_days' => round((float) LeaveRequest::query()->where('status', 'Pending')->sum('days'), 2),
            'on_leave_now' => $onLeaveNow->map(fn (LeaveRequest $l): array => $this->requestRow($l))->all(),
            'on_leave_today' => $onLeaveNow->count(),
            'types' => $typeRows,
            'spendable_pool' => round($totalEntitlement - $longPool, 2),
            'long_leave_pool' => round($longPool, 2),
            'totals' => [
                'entitlement_pool' => round($totalEntitlement, 2),
                'approved' => round($totalApproved, 2),
                'pending' => round($totalPending, 2),
                'utilisation' => $totalEntitlement > 0 ? round($totalApproved / $totalEntitlement * 100, 1) : null,
                'active_employees' => (int) Employee::query()->where('is_active', true)->count(),
            ],
            'series' => $series,
            'year_state' => [
                'previous_closed' => $lastYearClosed > 0,
                'current_closed' => $thisYearClosed > 0,
                'previous_rows' => $lastYearClosed,
                'current_rows' => $thisYearClosed,
            ],
            /*
             * What closing this year would throw away under the policy in force. Without a carry-forward
             * rule every unused day in the register lapses — a figure nobody sees until they press the
             * button, which is why the desk states it before the year-end screen is opened.
             */
            'lapse_preview' => $this->lapsePreview($year),
        ];
    }

    /**
     * Days that would lapse if the year closed now, and which types they belong to.
     *
     * Reads the year-end dry run rather than re-deriving it, so the desk and the year-end screen can
     * never disagree about a figure this consequential.
     */
    private function lapsePreview(int $year): array
    {
        $preview = app(LeaveYearEndService::class)->preview($year);
        $lapsing = array_values(array_filter($preview['types'], fn (array $t): bool => ($t['lapsed'] ?? 0) > 0));

        // Split the same way the granted pool is split: maternity alone is most of this and it is not
        // leave anybody was about to take, so a single 226,771d heading reads as an emergency.
        $longDays = array_sum(array_map(
            fn (array $t): float => (float) ($t['days_per_year'] ?? 0) >= LeaveBalanceService::LONG_LEAVE_DAYS ? (float) $t['lapsed'] : 0.0,
            $lapsing,
        ));

        return [
            'days' => round((float) $preview['totals']['lapsed'], 2),
            'days_long' => round($longDays, 2),
            'days_spendable' => round((float) $preview['totals']['lapsed'] - $longDays, 2),
            'carry' => round((float) $preview['totals']['carry'], 2),
            'types' => array_map(fn (array $t): array => [
                'name' => $t['leave_type'],
                'lapsed' => $t['lapsed'],
                'is_carry_forward' => $t['is_carry_forward'],
                // Named so the desk can say when the whole figure is a benefit map rather than leave
                // somebody was about to take. Maternity is most of this hospital's granted days.
                'is_long_leave' => (float) ($t['days_per_year'] ?? 0) >= LeaveBalanceService::LONG_LEAVE_DAYS,
            ], $lapsing),
            'already_closed' => (int) $preview['closed'],
        ];
    }

    /** Recent decisions, for the desk's trail. */
    public function recentDecisions(int $limit = 12): array
    {
        return LeaveApprovalAction::query()
            ->with(['actor:id,name'])
            ->whereNotNull('decided_at')
            ->orderByDesc('decided_at')
            ->limit($limit)
            ->get()
            ->map(function (LeaveApprovalAction $action): array {
                $leave = LeaveRequest::query()
                    ->with(['employee:id,employee_id,full_name,first_name,last_name', 'leaveType:id,name,code,color'])
                    ->find($action->leave_request_id);

                return [
                    'id' => $action->id,
                    'leave_request_id' => $action->leave_request_id,
                    'decision' => $action->decision,
                    'stage' => $action->stage_name,
                    'actor' => $action->actor?->name,
                    'comments' => $action->comments,
                    'decided_at' => $action->decided_at?->toDateTimeString(),
                    'employee' => $leave?->employee?->full_name ?: $leave?->employee_id,
                    'leave_type' => $leave?->leaveType?->name,
                    'leave_type_color' => $leave?->leaveType?->color,
                    'days' => $leave !== null ? (float) $leave->days : null,
                ];
            })
            ->all();
    }

    /** One application, in the shape the detail page draws. */
    public function requestRow(LeaveRequest $leave): array
    {
        $employee = $leave->employee;

        return [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee' => $employee?->full_name ?: trim((($employee?->first_name ?? '').' '.($employee?->last_name ?? ''))) ?: $leave->employee_id,
            'department' => $employee?->department?->name,
            'leave_type' => $leave->leaveType?->name,
            'leave_type_code' => $leave->leaveType?->code,
            'leave_type_color' => $leave->leaveType?->color,
            'from_date' => $leave->from_date?->toDateString(),
            'to_date' => $leave->to_date?->toDateString(),
            'days' => round((float) $leave->days, 2),
            'is_half_day' => (bool) $leave->is_half_day,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'created_at' => $leave->created_at?->toDateTimeString(),
            'approved_at' => $leave->approved_at?->toDateTimeString(),
        ];
    }

    /**
     * The approval desk queue: requests grouped by how long they have been waiting.
     */
    public function approvalQueue(): array
    {
        $rows = LeaveRequest::query()
            ->with(['employee:id,employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->where('status', 'Pending')
            ->orderBy('from_date')
            ->get()
            ->map(function (LeaveRequest $leave): array {
                $row = $this->requestRow($leave);
                $row['waiting_days'] = $leave->created_at !== null ? (int) $leave->created_at->diffInDays(now()) : 0;
                $row['starts_in_days'] = $leave->from_date !== null ? (int) now()->startOfDay()->diffInDays($leave->from_date->startOfDay(), false) : 0;

                return $row;
            })
            ->all();

        return [
            'rows' => $rows,
            'total' => count($rows),
            'days_at_stake' => round(array_sum(array_column($rows, 'days')), 2),
            'overdue' => count(array_filter($rows, fn (array $r): bool => $r['waiting_days'] >= 3)),
            'already_started' => count(array_filter($rows, fn (array $r): bool => $r['starts_in_days'] < 0)),
        ];
    }

    /**
     * Everything about one application: the request, its trail, its balance position, what the approval
     * would actually cost the balance, and whether it is still legal to approve.
     */
    public function requestDetail(LeaveRequest $leave): array
    {
        $year = (int) $leave->from_date->format('Y');
        $balance = $this->balances->balance($leave->employee_id, (int) $leave->leave_type_id, $year);
        $history = app(LeaveRequestService::class)->approvalHistory($leave->id);
        $nextStage = app(LeaveRequestService::class)->nextApprovalStage($leave);

        $preview = null;
        try {
            $preview = $this->balances->previewDays(
                $leave->employee_id,
                (int) $leave->leave_type_id,
                $leave->from_date->toDateString(),
                $leave->to_date->toDateString(),
                (bool) $leave->is_half_day,
            );
        } catch (\Throwable) {
            $preview = null;
        }

        /*
         * A pending application has already reserved its days, and an approved one has already spent
         * them: in both cases the days are inside the balance that is reported. Subtracting them again
         * would charge the application twice and turn a covered request into a shortfall.
         *
         * So the question this page answers is "if the decision is yes, where does the balance stand?" —
         * which is the balance as it already reads, not the balance minus the days a second time.
         */
        $available = (float) ($balance['available_raw'] ?? $balance['available']);
        $wouldLeave = round($available, 2);
        $lastYear = $this->balances->balance($leave->employee_id, (int) $leave->leave_type_id, $year - 1);

        return [
            'request' => $this->requestRow($leave),
            'balance' => $balance,
            'previous_year_balance' => $lastYear,
            'history' => $history,
            'next_stage' => $nextStage,
            'stages' => $balance['approval_steps'],
            'calendar' => $preview,
            'position' => [
                'available_now' => $available,
                'after_approval' => $wouldLeave,
                'sufficient' => $leave->status === 'Rejected' ? true : $available >= 0,
                'shortfall' => $leave->status === 'Rejected' ? 0.0 : ($available < 0 ? abs($available) : 0.0),
                'reserved' => $leave->status === 'Pending' ? round((float) $leave->days, 2) : 0.0,
            ],
        ];
    }

    /** Type-level configuration + how much each type is actually used, for the Leave Types desk. */
    public function typeOverview(int $year): array
    {
        $types = LeaveType::query()->orderBy('name')->get();
        $settings = LeaveTypeSetting::query()->get()->keyBy('leave_type_id');

        return $types->map(function (LeaveType $type) use ($settings, $year): array {
            $setting = $settings->get($type->id);
            $approved = (float) LeaveRequest::query()->where('leave_type_id', $type->id)->where('status', 'Approved')->whereYear('from_date', $year)->sum('days');
            $taken = (int) LeaveRequest::query()->where('leave_type_id', $type->id)->whereYear('from_date', $year)->count();

            return [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'description' => $type->description,
                'days_per_year' => (float) $type->days_per_year,
                'is_paid' => (bool) $type->is_paid,
                'is_active' => (bool) $type->is_active,
                'day_count_mode' => $setting?->day_count_mode ?? 'calendar_days',
                'accrual_mode' => $setting?->accrual_mode ?? 'annual',
                'proration_mode' => $setting?->proration_mode ?? 'none',
                'working_days' => is_array($setting?->working_days) ? $setting->working_days : LeaveBalanceService::DEFAULT_WORKING_DAYS,
                'approval_steps' => is_array($setting?->approval_steps) && $setting->approval_steps !== [] ? $setting->approval_steps : ['HR'],
                'is_carry_forward' => (bool) ($setting?->is_carry_forward ?? false),
                'max_carry_days' => $setting?->max_carry_days === null ? null : (float) $setting->max_carry_days,
                'carry_expiry_months' => (int) ($setting?->carry_expiry_months ?? 3),
                'is_encashable' => (bool) ($setting?->is_encashable ?? false),
                'encash_max_days' => $setting?->encash_max_days === null ? null : (float) $setting->encash_max_days,
                'encash_salary_component' => $setting?->encash_salary_component ?? 'basic',
                'used_days' => round($approved, 2),
                'request_count' => $taken,
                'live_requests' => (int) LeaveRequest::query()->where('leave_type_id', $type->id)->whereIn('status', ['Pending', 'Approved'])->count(),
            ];
        })->all();
    }

    /**
     * The reports page: liability, use by type, use by department, and the people who never take leave.
     */
    public function report(int $year): array
    {
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();
        $activeEmployees = (int) Employee::query()->where('is_active', true)->count();

        $byType = $this->overview($year)['types'];

        // Long-leave entitlements (maternity and the like) are most of the pool and almost none of the
        // spend; the report states both so the headline figure cannot be read as spendable leave.
        $longPool = array_sum(array_map(
            fn (array $t): float => (float) $t['days_per_year'] >= LeaveBalanceService::LONG_LEAVE_DAYS ? (float) $t['entitlement_pool'] : 0.0,
            $byType,
        ));

        $byDepartment = DB::table('leave_requests as lr')
            ->join('employees as e', 'e.employee_id', '=', 'lr.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('lr.status', 'Approved')
            ->whereYear('lr.from_date', $year)
            ->groupBy('d.id', 'd.name')
            ->selectRaw('COALESCE(d.name, \'Unassigned\') as department, COUNT(DISTINCT lr.employee_id) as employees, SUM(lr.days) as days, COUNT(*) as requests')
            ->orderByDesc('days')
            ->get()
            ->map(fn ($row): array => [
                'department' => $row->department,
                'employees' => (int) $row->employees,
                'days' => round((float) $row->days, 2),
                'requests' => (int) $row->requests,
                'average' => $row->employees > 0 ? round((float) $row->days / (int) $row->employees, 2) : 0.0,
            ])
            ->all();

        $topTakers = DB::table('leave_requests as lr')
            ->join('employees as e', 'e.employee_id', '=', 'lr.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('lr.status', 'Approved')
            ->whereYear('lr.from_date', $year)
            ->groupBy('lr.employee_id', 'e.full_name', 'd.name')
            ->selectRaw('lr.employee_id, e.full_name as employee, d.name as department, SUM(lr.days) as days, COUNT(*) as requests')
            ->orderByDesc('days')
            ->limit(15)
            ->get()
            ->map(fn ($row): array => [
                'employee_id' => $row->employee_id,
                'employee' => $row->employee,
                'department' => $row->department,
                'days' => round((float) $row->days, 2),
                'requests' => (int) $row->requests,
            ])
            ->all();

        $carried = HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->sum('carry_forward');
        $lapsed = HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->sum('lapsed');

        /*
         * Decision latency, with the impossibility filtered out. Rows exist in this database whose
         * `approved_at` precedes their `created_at` (seed and import artefacts), and a plain AVERAGE
         * over them reported "-271 days from application to decision" — a number that cannot be true
         * and that nobody would trust a report from afterwards.
         */
        $decisionSpeed = DB::table('leave_requests')
            ->whereNotNull('approved_at')
            ->whereYear('from_date', $year)
            ->whereIn('status', ['Approved', 'Rejected'])
            ->selectRaw('COUNT(*) as decisions, AVG(CASE WHEN julianday(approved_at) >= julianday(created_at) THEN julianday(approved_at) - julianday(created_at) ELSE 0 END) as avg_days')
            ->first();

        // How many rows carry an impossible timestamp, so the screen can say so rather than hide it.
        $impossible = DB::table('leave_requests')
            ->whereNotNull('approved_at')
            ->whereYear('from_date', $year)
            ->whereIn('status', ['Approved', 'Rejected'])
            ->whereRaw('julianday(approved_at) < julianday(created_at)')
            ->count();

        return [
            'year' => $year,
            'types' => $byType,
            'departments' => $byDepartment,
            'top_takers' => $topTakers,
            'totals' => [
                'active_employees' => $activeEmployees,
                'approved_days' => round(array_sum(array_column($byType, 'approved')), 2),
                'pending_days' => round(array_sum(array_column($byType, 'pending')), 2),
                'entitlement_pool' => round(array_sum(array_column($byType, 'entitlement_pool')), 2),
                'entitlement_pool_spendable' => round(array_sum(array_column($byType, 'entitlement_pool')) - $longPool, 2),
                'entitlement_pool_long' => round($longPool, 2),
                'carried_forward' => round((float) $carried, 2),
                'lapsed' => round((float) $lapsed, 2),
                'year_closed' => HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->exists(),
                'average_days_per_employee' => $activeEmployees > 0 ? round(array_sum(array_column($byType, 'approved')) / $activeEmployees, 2) : 0.0,
                'decisions' => (int) ($decisionSpeed->decisions ?? 0),
                'average_decision_days' => $decisionSpeed?->avg_days !== null ? round((float) $decisionSpeed->avg_days, 1) : null,
                'impossible_timestamps' => (int) $impossible,
            ],
            'types_count' => $types->count(),
        ];
    }

    /** Personal view: my own applications and my own balances. */
    public function myLeave(string $employeeId, int $year): array
    {
        $requests = LeaveRequest::query()
            ->with('leaveType:id,name,code,color')
            ->where('employee_id', $employeeId)
            ->orderByDesc('from_date')
            ->limit(100)
            ->get();

        $balances = $this->balances->balanceForAllTypes($employeeId, $year);

        return [
            'requests' => $requests->map(fn (LeaveRequest $l): array => $this->requestRow($l))->all(),
            'balances' => $balances,
            'year' => $year,
            'totals' => [
                'available' => round(array_sum(array_column($balances, 'available')), 2),
                'taken' => round(array_sum(array_column($balances, 'approved')), 2),
                'pending' => round((float) $requests->where('status', 'Pending')->sum('days'), 2),
            ],
        ];
    }
}

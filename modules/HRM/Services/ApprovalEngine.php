<?php

namespace Modules\HRM\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Approval\OvertimeApprovalAction;
use Modules\HRM\Models\Approval\OvertimeRequestWorkflow;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSupervisor;
use Modules\HRM\Models\Leave\LeaveApprovalAction;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveRequestWorkflow;
use Modules\HRM\Notifications\ApprovalNotification;

/**
 * Dynamic approval engine for Leave + Overtime/Extra-Duty (Phase 2).
 *
 * One engine, two flows, both driven by editable chains
 * (approval_chains + approval_chain_steps):
 *
 *   leave:    Supervisor → HR and Admin → DGM HR and Admin → General Manager → CEO
 *   overtime: Supervisor → HR and Admin → DGM HR and Admin → General Manager
 *
 * Hybrid stage→approver resolution (locked decision):
 *   - a `supervisor` stage resolves from the applicant's employee_supervisors
 *     mapping (whoever supervises THIS applicant);
 *   - a `role` stage resolves from the role (whoever currently holds it).
 *
 * Each request snapshots the chain labels at creation, so editing a chain
 * only affects NEW requests — in-flight ones keep walking the chain they
 * started on. Leave writes reuse LeaveRequestService (balance re-check,
 * attendance sync and web behaviour stay identical); OT writes live here
 * because the roster desk's approve() is single-stage by design.
 *
 * Deliberately cache-free: chains are tiny tables, and a static cache would
 * leak a seeded chain from one test into the next within the same process.
 */
class ApprovalEngine
{
    /**
     * Ordered step definitions for an active chain, or null when none is
     * configured (callers fall back to their legacy single stage).
     *
     * @return list<array{position:int,stage_key:string,stage_label:string,resolver:string,role_name:?string}>|null
     */
    public function steps(string $chainKey): ?array
    {
        return ApprovalChain::activeStepsFor($chainKey);
    }

    /**
     * Stage labels only — the shape the leave workflow snapshot stores.
     *
     * @return list<string>|null
     */
    public function chainLabels(string $chainKey): ?array
    {
        $steps = $this->steps($chainKey);

        if ($steps === null) {
            return null;
        }

        return array_map(fn (array $s): string => $s['stage_label'], $steps);
    }

    /** @return list<int> */
    public function supervisorIds(string $employeeCode): array
    {
        return EmployeeSupervisor::where('employee_id', $employeeCode)
            ->whereNotNull('supervisor_id')
            ->pluck('supervisor_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()->values()->all();
    }

    /** @return list<int> */
    public function roleIds(string $roleName): array
    {
        return User::role($roleName)->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * User ids allowed to decide a step for THIS applicant.
     *
     * @param  array{resolver:string,role_name:?string}  $step
     * @return list<int>
     */
    public function stageApprovers(array $step, string $employeeCode): array
    {
        if (($step['resolver'] ?? '') === 'supervisor') {
            return $this->supervisorIds($employeeCode);
        }

        if (($step['resolver'] ?? '') === 'role' && ! empty($step['role_name'])) {
            return $this->roleIds($step['role_name']);
        }

        return [];
    }

    /**
     * Legacy fallback when no chain (or no step at this position) resolves:
     * whoever holds the desk permission. Keeps old single-stage installs
     * approvable instead of deadlocking every request.
     *
     * @return list<int>
     */
    public function fallbackApprovers(string $chainKey): array
    {
        $perm = $chainKey === ApprovalChain::OVERTIME ? 'hrm.overtimes.approve' : 'hrm.leaves.approve';

        return User::permission($perm)->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Where a leave sits: snapshot labels + current position + who decides now.
     *
     * @return array{steps:list<string>,current:int,total:int,label:?string,is_final:bool,approver_ids:list<int>}
     */
    public function leaveStage(LeaveRequest $leave): array
    {
        $workflow = LeaveRequestWorkflow::where('leave_request_id', $leave->id)->first();
        $steps = ($workflow !== null && is_array($workflow->steps) && $workflow->steps !== [])
            ? array_values($workflow->steps)
            : ($this->chainLabels(ApprovalChain::LEAVE) ?? ['HR']);
        $current = $workflow !== null ? (int) $workflow->current_stage : 0;

        return $this->stageInfo(ApprovalChain::LEAVE, $leave->employee_id, $steps, $current);
    }

    /**
     * @return array{steps:list<string>,current:int,total:int,label:?string,is_final:bool,approver_ids:list<int>}
     */
    public function overtimeStage(\Modules\HRM\Models\Roster\RosterOvertime $overtime): array
    {
        $workflow = OvertimeRequestWorkflow::where('overtime_id', $overtime->id)->first();
        $steps = ($workflow !== null && is_array($workflow->steps) && $workflow->steps !== [])
            ? array_values($workflow->steps)
            : ($this->chainLabels(ApprovalChain::OVERTIME) ?? ['HR']);
        $current = $workflow !== null ? (int) $workflow->current_stage : 0;

        return $this->stageInfo(ApprovalChain::OVERTIME, $overtime->employee_id, $steps, $current);
    }

    /**
     * @param  list<string>  $steps
     * @return array{steps:list<string>,current:int,total:int,label:?string,is_final:bool,approver_ids:list<int>}
     */
    private function stageInfo(string $chainKey, string $employeeCode, array $steps, int $current): array
    {
        $total = count($steps);
        $label = $steps[$current] ?? null;
        $approvers = [];

        if ($label !== null) {
            $definitions = $this->steps($chainKey);
            $definition = $definitions !== null ? ($definitions[$current] ?? null) : null;
            $approvers = $definition !== null
                ? $this->stageApprovers($definition, $employeeCode)
                : $this->fallbackApprovers($chainKey);
        }

        return [
            'steps' => $steps,
            'current' => $current,
            'total' => $total,
            'label' => $label,
            'is_final' => $label !== null && $current + 1 >= $total,
            'approver_ids' => $approvers,
        ];
    }

    /** @return array{0:bool,1:?string} ok + reason when not */
    public function canApproveLeave(User $user, LeaveRequest $leave): array
    {
        if ($leave->status !== 'Pending') {
            return [false, "This application is already {$leave->status}."];
        }

        $stage = $this->leaveStage($leave);

        if ($stage['label'] === null) {
            return [false, 'Every approval stage on this application is already complete.'];
        }

        if (! in_array($user->id, $stage['approver_ids'], true)) {
            return [false, "This application is waiting on '{$stage['label']}', not on you."];
        }

        return [true, null];
    }

    /** @return array{0:bool,1:?string} */
    public function canApproveOvertime(User $user, \Modules\HRM\Models\Roster\RosterOvertime $overtime): array
    {
        if ($overtime->status !== 'Pending') {
            return [false, "This record is already {$overtime->status}."];
        }

        $stage = $this->overtimeStage($overtime);

        if ($stage['label'] === null) {
            return [false, 'Every approval stage on this record is already complete.'];
        }

        if (! in_array($user->id, $stage['approver_ids'], true)) {
            return [false, "This record is waiting on '{$stage['label']}', not on you."];
        }

        return [true, null];
    }

    /**
     * Everything waiting on this user's desk, both flows.
     *
     * @return array{leaves:list<array<string,mixed>>,overtimes:list<array<string,mixed>>}
     */
    public function queueFor(User $user): array
    {
        $leaves = LeaveRequest::where('status', 'Pending')
            ->with(['employee:employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name', 'leaveType:id,name,code,color'])
            ->orderBy('id')
            ->get();

        $leaveWorkflows = LeaveRequestWorkflow::whereIn('leave_request_id', $leaves->pluck('id')->all())
            ->get()->keyBy('leave_request_id');
        $leaveActionCounts = LeaveApprovalAction::whereIn('leave_request_id', $leaves->pluck('id')->all())
            ->selectRaw('leave_request_id, COUNT(*) AS c')->groupBy('leave_request_id')
            ->pluck('c', 'leave_request_id');

        $leaveQueue = [];
        foreach ($leaves as $leave) {
            $workflow = $leaveWorkflows->get($leave->id);
            $steps = ($workflow !== null && is_array($workflow->steps) && $workflow->steps !== [])
                ? array_values($workflow->steps)
                : ($this->chainLabels(ApprovalChain::LEAVE) ?? ['HR']);
            $stage = $this->stageInfo(ApprovalChain::LEAVE, $leave->employee_id, $steps, $workflow !== null ? (int) $workflow->current_stage : 0);

            if ($stage['label'] === null || ! in_array($user->id, $stage['approver_ids'], true)) {
                continue;
            }

            $employee = $leave->employee;
            $leaveQueue[] = [
                'id' => $leave->id,
                'applicant' => [
                    'employee_id' => $leave->employee_id,
                    'name' => $employee?->full_name ?: trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
                    'department' => $employee?->department?->name,
                ],
                'leave_type' => $leave->leaveType?->name,
                'from_date' => $leave->from_date?->toDateString(),
                'to_date' => $leave->to_date?->toDateString(),
                'days' => (float) $leave->days,
                'is_half_day' => (bool) $leave->is_half_day,
                'reason' => $leave->reason,
                'stage_label' => $stage['label'],
                'stage_position' => $stage['current'] + 1,
                'stage_total' => $stage['total'],
                'is_final' => $stage['is_final'],
                'decisions_so_far' => (int) ($leaveActionCounts[$leave->id] ?? 0),
                'applied_at' => $leave->created_at?->toDateTimeString(),
            ];
        }

        $ots = \Modules\HRM\Models\Roster\RosterOvertime::where('status', 'Pending')
            ->with(['employee:employee_id,full_name,first_name,last_name,department_id', 'employee.department:id,name'])
            ->orderBy('overtime_date')->orderBy('id')
            ->get();

        $otWorkflows = OvertimeRequestWorkflow::whereIn('overtime_id', $ots->pluck('id')->all())
            ->get()->keyBy('overtime_id');
        $otActionCounts = OvertimeApprovalAction::whereIn('overtime_id', $ots->pluck('id')->all())
            ->selectRaw('overtime_id, COUNT(*) AS c')->groupBy('overtime_id')
            ->pluck('c', 'overtime_id');

        $otQueue = [];
        foreach ($ots as $ot) {
            $workflow = $otWorkflows->get($ot->id);
            $steps = ($workflow !== null && is_array($workflow->steps) && $workflow->steps !== [])
                ? array_values($workflow->steps)
                : ($this->chainLabels(ApprovalChain::OVERTIME) ?? ['HR']);
            $stage = $this->stageInfo(ApprovalChain::OVERTIME, $ot->employee_id, $steps, $workflow !== null ? (int) $workflow->current_stage : 0);

            if ($stage['label'] === null || ! in_array($user->id, $stage['approver_ids'], true)) {
                continue;
            }

            $employee = $ot->employee;
            $otQueue[] = [
                'id' => $ot->id,
                'applicant' => [
                    'employee_id' => $ot->employee_id,
                    'name' => $employee?->full_name ?: trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
                    'department' => $employee?->department?->name,
                ],
                'overtime_type' => $ot->overtime_type,
                'overtime_date' => $ot->overtime_date?->toDateString(),
                'hours' => (float) $ot->hours,
                'reason' => $ot->reason,
                'stage_label' => $stage['label'],
                'stage_position' => $stage['current'] + 1,
                'stage_total' => $stage['total'],
                'is_final' => $stage['is_final'],
                'decisions_so_far' => (int) ($otActionCounts[$ot->id] ?? 0),
                'applied_at' => $ot->created_at?->toDateTimeString(),
            ];
        }

        return ['leaves' => $leaveQueue, 'overtimes' => $otQueue];
    }

    /**
     * Decisions this user has taken, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function historyFor(User $user, ?string $type = null, int $limit = 50): array
    {
        $rows = [];

        if ($type === null || $type === 'leave') {
            $actions = LeaveApprovalAction::where('actor_id', $user->id)
                ->with(['leaveRequest:id,employee_id,leave_type_id,from_date,to_date,days,status', 'leaveRequest.leaveType:id,name'])
                ->orderByDesc('decided_at')->limit($limit)->get();

            foreach ($actions as $action) {
                $leave = $action->leaveRequest;
                $rows[] = [
                    'ref_type' => 'leave',
                    'ref_id' => $action->leave_request_id,
                    'decision' => $action->decision,
                    'stage_name' => $action->stage_name,
                    'comments' => $action->comments,
                    'decided_at' => $action->decided_at?->toDateTimeString(),
                    'summary' => $leave !== null
                        ? "{$leave->employee_id} · {$leave->leaveType?->name} · {$leave->from_date} → {$leave->to_date} ({$leave->days}d) · {$leave->status}"
                        : null,
                ];
            }
        }

        if ($type === null || $type === 'overtime') {
            $actions = OvertimeApprovalAction::where('actor_id', $user->id)
                ->with(['overtime:id,employee_id,overtime_type,overtime_date,hours,status'])
                ->orderByDesc('decided_at')->limit($limit)->get();

            foreach ($actions as $action) {
                $ot = $action->overtime;
                $rows[] = [
                    'ref_type' => 'overtime',
                    'ref_id' => $action->overtime_id,
                    'decision' => $action->decision,
                    'stage_name' => $action->stage_name,
                    'comments' => $action->comments,
                    'decided_at' => $action->decided_at?->toDateTimeString(),
                    'summary' => $ot !== null
                        ? "{$ot->employee_id} · {$ot->overtime_type} · {$ot->overtime_date} ({$ot->hours}h) · {$ot->status}"
                        : null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['decided_at'] ?? ''), (string) ($a['decided_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    public function approveLeave(User $user, LeaveRequest $leave, ?string $comments = null): LeaveRequest
    {
        [$ok, $reason] = $this->canApproveLeave($user, $leave);

        if (! $ok) {
            throw new AuthorizationException($reason ?? 'You cannot approve this application.');
        }

        // Same staged write the web desk uses (balance re-check on the final
        // stage, attendance sync) — the engine adds authorization + notify.
        $leave = app(LeaveRequestService::class)->approve($leave, $comments);

        $this->safelyNotify(fn () => $this->notifyLeaveMoved($user, $leave->fresh(), 'Approved', $comments));

        return $leave;
    }

    public function rejectLeave(User $user, LeaveRequest $leave, ?string $comments = null): LeaveRequest
    {
        [$ok, $reason] = $this->canApproveLeave($user, $leave);

        if (! $ok) {
            throw new AuthorizationException($reason ?? 'You cannot reject this application.');
        }

        $leave = app(LeaveRequestService::class)->reject($leave, $comments);

        $this->safelyNotify(fn () => $this->notifyLeaveMoved($user, $leave->fresh(), 'Rejected', $comments));

        return $leave;
    }

    public function approveOvertime(User $user, \Modules\HRM\Models\Roster\RosterOvertime $overtime, ?float $hours = null, ?string $comments = null): \Modules\HRM\Models\Roster\RosterOvertime
    {
        [$ok, $reason] = $this->canApproveOvertime($user, $overtime);

        if (! $ok) {
            throw new AuthorizationException($reason ?? 'You cannot approve this record.');
        }

        $overtime = DB::transaction(function () use ($overtime, $user, $hours, $comments) {
            $locked = \Modules\HRM\Models\Roster\RosterOvertime::query()->whereKey($overtime->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Pending') {
                throw new AuthorizationException("This record is already {$locked->status}.");
            }

            $workflow = OvertimeRequestWorkflow::query()->where('overtime_id', $locked->id)->lockForUpdate()->first();

            if ($workflow === null) {
                $workflow = OvertimeRequestWorkflow::create([
                    'overtime_id' => $locked->id,
                    'steps' => $this->chainLabels(ApprovalChain::OVERTIME) ?? ['HR'],
                    'current_stage' => 0,
                ]);
            }

            $steps = is_array($workflow->steps) && $workflow->steps !== [] ? array_values($workflow->steps) : ['HR'];
            $step = (int) $workflow->current_stage;

            OvertimeApprovalAction::create([
                'overtime_id' => $locked->id,
                'stage_index' => $step + 1,
                'stage_name' => $steps[$step] ?? 'HR',
                'decision' => 'Approved',
                'actor_id' => $user->id,
                'comments' => $comments,
                'decided_at' => now(),
            ]);
            $workflow->update(['current_stage' => $step + 1]);

            if ($step + 1 >= count($steps)) {
                $locked->update([
                    'status' => 'Approved',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    ...(null !== $hours ? ['hours' => $hours] : []),
                ]);
            }

            return $locked->refresh();
        });

        $this->safelyNotify(fn () => $this->notifyOvertimeMoved($user, $overtime, 'Approved', $comments));

        return $overtime;
    }

    public function rejectOvertime(User $user, \Modules\HRM\Models\Roster\RosterOvertime $overtime, ?string $reason = null): \Modules\HRM\Models\Roster\RosterOvertime
    {
        [$ok, $denied] = $this->canApproveOvertime($user, $overtime);

        if (! $ok) {
            throw new AuthorizationException($denied ?? 'You cannot reject this record.');
        }

        $overtime = DB::transaction(function () use ($overtime, $user, $reason) {
            $locked = \Modules\HRM\Models\Roster\RosterOvertime::query()->whereKey($overtime->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Pending') {
                throw new AuthorizationException("This record is already {$locked->status}.");
            }

            $workflow = OvertimeRequestWorkflow::query()->where('overtime_id', $locked->id)->lockForUpdate()->first();
            $steps = ($workflow !== null && is_array($workflow->steps) && $workflow->steps !== [])
                ? array_values($workflow->steps)
                : ($this->chainLabels(ApprovalChain::OVERTIME) ?? ['HR']);
            $step = $workflow !== null ? (int) $workflow->current_stage : 0;

            OvertimeApprovalAction::create([
                'overtime_id' => $locked->id,
                'stage_index' => $step + 1,
                'stage_name' => $steps[$step] ?? 'HR',
                'decision' => 'Rejected',
                'actor_id' => $user->id,
                'comments' => $reason,
                'decided_at' => now(),
            ]);

            $locked->update(['status' => 'Rejected']);

            return $locked->refresh();
        });

        $this->safelyNotify(fn () => $this->notifyOvertimeMoved($user, $overtime, 'Rejected', $reason));

        return $overtime;
    }

    /**
     * Ping the first stage's desk when a leave is filed. Called from
     * LeaveRequestService::create, so web, mobile and imports all feed it —
     * and skipped entirely when no chain is configured (legacy flow untouched).
     */
    public function notifyNewLeave(LeaveRequest $leave): void
    {
        $this->safelyNotify(function () use ($leave): void {
            $stage = $this->leaveStage($leave);

            if ($stage['label'] === null || $stage['approver_ids'] === []) {
                return;
            }

            $this->ping(
                $stage['approver_ids'],
                [],
                "New leave application from {$leave->employee_id}",
                "{$leave->days} day(s): {$leave->from_date} → {$leave->to_date}. Waiting on {$stage['label']}.",
                'leave',
                $leave->id,
                ['stage' => $stage['label']],
            );
        });
    }

    /** Same, for an OT/ED record (called from the mobile claim endpoint). */
    public function notifyNewOvertime(\Modules\HRM\Models\Roster\RosterOvertime $overtime): void
    {
        $this->safelyNotify(function () use ($overtime): void {
            $stage = $this->overtimeStage($overtime);

            if ($stage['label'] === null || $stage['approver_ids'] === []) {
                return;
            }

            $this->ping(
                $stage['approver_ids'],
                [],
                "New {$overtime->overtime_type} claim from {$overtime->employee_id}",
                "{$overtime->overtime_date} · {$overtime->hours}h. Waiting on {$stage['label']}.",
                'overtime',
                $overtime->id,
                ['stage' => $stage['label']],
            );
        });
    }

    private function notifyLeaveMoved(User $actor, LeaveRequest $leave, string $decision, ?string $comments): void
    {
        $applicantId = $this->applicantUserId($leave->employee_id);

        if ($leave->status !== 'Pending') {
            $this->ping(
                [],
                $applicantId !== null ? [$applicantId] : [],
                "Leave {$leave->status}: {$leave->from_date} → {$leave->to_date}",
                $decision === 'Approved'
                    ? "Final approval complete ({$leave->days} day(s))."
                    : ('Rejected'.($comments !== null && $comments !== '' ? ": {$comments}" : '.')),
                'leave',
                $leave->id,
                ['decision' => $decision],
            );

            return;
        }

        $stage = $this->leaveStage($leave);

        $this->ping(
            array_values(array_diff($stage['approver_ids'], [$actor->id])),
            $applicantId !== null ? [$applicantId] : [],
            "Leave moved to {$stage['label']}",
            "{$leave->employee_id}: {$leave->from_date} → {$leave->to_date} ({$leave->days}d).",
            'leave',
            $leave->id,
            ['stage' => $stage['label'], 'decision' => $decision],
        );
    }

    private function notifyOvertimeMoved(User $actor, \Modules\HRM\Models\Roster\RosterOvertime $overtime, string $decision, ?string $comments): void
    {
        $applicantId = $this->applicantUserId($overtime->employee_id);

        if ($overtime->status !== 'Pending') {
            $this->ping(
                [],
                $applicantId !== null ? [$applicantId] : [],
                ucfirst(strtolower($overtime->overtime_type))." {$overtime->status}: {$overtime->overtime_date}",
                $decision === 'Approved'
                    ? "Final approval complete ({$overtime->hours}h)."
                    : ('Rejected'.($comments !== null && $comments !== '' ? ": {$comments}" : '.')),
                'overtime',
                $overtime->id,
                ['decision' => $decision],
            );

            return;
        }

        $stage = $this->overtimeStage($overtime);

        $this->ping(
            array_values(array_diff($stage['approver_ids'], [$actor->id])),
            $applicantId !== null ? [$applicantId] : [],
            ucfirst(strtolower($overtime->overtime_type))." moved to {$stage['label']}",
            "{$overtime->employee_id}: {$overtime->overtime_date} ({$overtime->hours}h).",
            'overtime',
            $overtime->id,
            ['stage' => $stage['label'], 'decision' => $decision],
        );
    }

    private function applicantUserId(string $employeeCode): ?int
    {
        $id = Employee::where('employee_id', $employeeCode)->value('user_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<int>  $deskIds
     * @param  list<int>  $applicantIds
     */
    private function ping(array $deskIds, array $applicantIds, string $title, string $body, string $refType, int $refId, array $extra = []): void
    {
        $ids = array_values(array_unique(array_merge($deskIds, $applicantIds)));

        if ($ids === []) {
            return;
        }

        $users = User::whereIn('id', $ids)->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new ApprovalNotification($title, $body, $refType, $refId, $extra));
    }

    /**
     * A notification must never break an approval: queue hiccups, missing
     * tables in old test fixtures and resolver surprises all land here.
     */
    private function safelyNotify(callable $notify): void
    {
        try {
            $notify();
        } catch (\Throwable $e) {
            Log::warning('Approval notification skipped: '.$e->getMessage());
        }
    }
}

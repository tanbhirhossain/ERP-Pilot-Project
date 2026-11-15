<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\AttendanceDailyLeaveMark;
use Modules\HRM\Models\Leave\LeaveApprovalAction;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveRequestWorkflow;
use Modules\HRM\Models\Leave\LeaveType;

/**
 * The leave application lifecycle: apply → approve (per stage) → attendance.
 *
 * What changed here, and why:
 *
 *  - Approval now re-checks the balance. It used to be checked only on create, so a policy change
 *    between applying and approving could approve leave the employee did not have.
 *  - A rejection requires a reason and is kept in the approval trail. "Rejected" with no explanation is
 *    not an auditable HR decision.
 *  - Approving stamps the attendance calendar ("On Leave") for the charged dates only — a half day no
 *    longer wipes the whole day — and records exactly which rows it touched, so cancelling or rejecting
 *    the leave can put them back. Before, nothing was ever removed and a cancelled leave stayed on the
 *    attendance calendar for ever.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly LeaveRequestRepositoryInterface $repo,
        private readonly LeaveBalanceService $balances,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function findOrFail(int $id): LeaveRequest
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $d): LeaveRequest
    {
        return DB::transaction(function () use ($d) {
            Employee::query()->where('employee_id', $d['employee_id'])->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap($d['employee_id'], $d['from_date'], $d['to_date']);
            $d['days'] = $this->balances->assertAvailable(
                $d['employee_id'],
                (int) $d['leave_type_id'],
                $d['from_date'],
                $d['to_date'],
                (bool) ($d['is_half_day'] ?? false),
            );
            $d['status'] = 'Pending';
            $leave = $this->repo->create($d);

            $type = LeaveType::findOrFail($leave->leave_type_id);
            LeaveRequestWorkflow::create([
                'leave_request_id' => $leave->id,
                'steps' => $this->balances->settings($type)['approval_steps'] ?: ['HR'],
                'current_stage' => 0,
            ]);

            return $leave;
        });
    }

    public function update(LeaveRequest $r, array $d): LeaveRequest
    {
        if ($r->status !== 'Pending') {
            throw ValidationException::withMessages(['status' => 'Only a pending application can be edited. Approve or reject it first.']);
        }
        $employeeId = $d['employee_id'] ?? $r->employee_id;
        $typeId = (int) ($d['leave_type_id'] ?? $r->leave_type_id);
        $from = $d['from_date'] ?? $r->from_date->toDateString();
        $to = $d['to_date'] ?? $r->to_date->toDateString();
        $half = (bool) ($d['is_half_day'] ?? $r->is_half_day);

        $datesMoved = $employeeId !== $r->employee_id
            || $from !== $r->from_date->toDateString()
            || $to !== $r->to_date->toDateString();

        if ($datesMoved) {
            $this->assertNoOverlap($employeeId, $from, $to, $r->id);
        }
        if ($datesMoved || $typeId !== (int) $r->leave_type_id || $half !== (bool) $r->is_half_day) {
            $d['days'] = $this->balances->assertAvailable($employeeId, $typeId, $from, $to, $half, $r->id);
        }

        return $this->repo->update($r, $d);
    }

    public function delete(LeaveRequest $r): void
    {
        DB::transaction(function () use ($r) {
            // Anything this application put on the attendance calendar goes back with it.
            $this->releaseAttendance($r);
            $this->repo->delete($r);
        });
    }

    /**
     * Advance one approval stage.
     *
     * The workflow row is created on apply, so a request always knows how many stages it needs. The
     * previous version re-derived the stage from "how many approvals exist" when the row was missing,
     * which double-counted on a retry.
     */
    public function approve(LeaveRequest $r): LeaveRequest
    {
        return DB::transaction(function () use ($r) {
            $locked = LeaveRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Pending') {
                throw ValidationException::withMessages(['status' => "This application is already {$locked->status}."]);
            }

            $type = LeaveType::findOrFail($locked->leave_type_id);
            $settings = $this->balances->settings($type);

            $workflow = LeaveRequestWorkflow::query()->where('leave_request_id', $locked->id)->lockForUpdate()->first();
            if ($workflow === null) {
                $workflow = LeaveRequestWorkflow::create([
                    'leave_request_id' => $locked->id,
                    'steps' => $settings['approval_steps'] ?: ['HR'],
                    'current_stage' => 0,
                ]);
            }

            $steps = is_array($workflow->steps) && $workflow->steps !== [] ? $workflow->steps : ['HR'];
            $step = (int) $workflow->current_stage;
            if ($step >= count($steps)) {
                throw ValidationException::withMessages(['status' => 'Every approval stage on this application is already complete.']);
            }

            // Final stage: the balance is re-checked against the figures as they stand now.
            $isFinal = $step + 1 >= count($steps);
            if ($isFinal) {
                $this->balances->assertAvailable(
                    $locked->employee_id,
                    (int) $locked->leave_type_id,
                    $locked->from_date->toDateString(),
                    $locked->to_date->toDateString(),
                    (bool) $locked->is_half_day,
                    $locked->id,
                );
            }

            LeaveApprovalAction::create([
                'leave_request_id' => $locked->id,
                'stage_index' => $step + 1,
                'stage_name' => $steps[$step] ?? 'HR',
                'decision' => 'Approved',
                'actor_id' => auth()->id(),
                'decided_at' => now(),
            ]);
            $workflow->update(['current_stage' => $step + 1]);

            if ($isFinal) {
                $locked->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                $this->syncToAttendance($locked, $settings);
            }

            return $locked->refresh();
        });
    }

    public function reject(LeaveRequest $r, ?string $comments = null): LeaveRequest
    {
        return DB::transaction(function () use ($r, $comments) {
            $locked = LeaveRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Pending') {
                throw ValidationException::withMessages(['status' => "This application is already {$locked->status}."]);
            }

            $type = LeaveType::findOrFail($locked->leave_type_id);
            $settings = $this->balances->settings($type);

            $workflow = LeaveRequestWorkflow::query()->where('leave_request_id', $locked->id)->lockForUpdate()->first();
            $step = $workflow !== null ? (int) $workflow->current_stage : 0;
            $steps = $workflow !== null && is_array($workflow->steps) ? $workflow->steps : ($settings['approval_steps'] ?: ['HR']);

            LeaveApprovalAction::create([
                'leave_request_id' => $locked->id,
                'stage_index' => $step + 1,
                'stage_name' => $steps[$step] ?? 'HR',
                'decision' => 'Rejected',
                'actor_id' => auth()->id(),
                'comments' => $comments,
                'decided_at' => now(),
            ]);
            $locked->update(['status' => 'Rejected', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            $this->releaseAttendance($locked);

            return $locked->refresh();
        });
    }

    /**
     * Put an approved application on the attendance calendar.
     *
     * Only charged dates are marked: a half day marks the day as half, a working-day leave type skips
     * weekends and holidays, and a day that already has punches is left alone — the employee was there.
     */
    private function syncToAttendance(LeaveRequest $leave, array $settings): void
    {
        $employee = Employee::with('department')->where('employee_id', $leave->employee_id)->first();
        if ($employee === null) {
            return;
        }

        $start = Carbon::parse($leave->from_date)->startOfDay();
        $end = Carbon::parse($leave->to_date)->startOfDay();
        $holidays = $this->balances->holidaysFor($leave->employee_id, $start->toDateString(), $end->toDateString(), $employee);
        $half = (bool) $leave->is_half_day && $start->isSameDay($end);

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if ($settings['day_count_mode'] === 'working_days'
                && (! in_array($date->dayOfWeek, $settings['working_days'], true) || in_array($date->toDateString(), $holidays, true))) {
                continue;
            }

            $daily = AttendanceDaily::firstOrNew(['employee_id' => $leave->employee_id, 'duty_date' => $date->toDateString()]);

            // Somebody who punched in was at work; leave does not overwrite a real attendance record.
            if ($daily->exists && ($daily->primary_punch_in || $daily->primary_punch_out)) {
                continue;
            }

            $previousStatus = $daily->status;
            $daily->status = 'On Leave';
            $daily->remarks = trim(($daily->remarks ? $daily->remarks.' | ' : '').'Approved leave #'.$leave->id.($half ? ' (half day)' : ''));
            $daily->save();

            AttendanceDailyLeaveMark::create([
                'leave_request_id' => $leave->id,
                'employee_id' => $leave->employee_id,
                'duty_date' => $date->toDateString(),
                'half_day' => $half,
                'previous_status' => $previousStatus,
            ]);
        }
    }

    /** Undo syncToAttendance(): restore the status each marked day had before the leave touched it. */
    private function releaseAttendance(LeaveRequest $leave): void
    {
        $marks = AttendanceDailyLeaveMark::query()->where('leave_request_id', $leave->id)->get();
        foreach ($marks as $mark) {
            $daily = AttendanceDaily::query()
                ->where('employee_id', $mark->employee_id)
                ->where('duty_date', $mark->duty_date)
                ->first();

            if ($daily !== null && $daily->status === 'On Leave' && ! $daily->primary_punch_in && ! $daily->primary_punch_out) {
                $daily->status = $mark->previous_status ?: 'Absent';
                $daily->remarks = trim(str_replace(' | Approved leave #'.$leave->id, '', (string) $daily->remarks));
                $daily->remarks = trim(preg_replace('/Approved leave #'.$leave->id.'( \(half day\))?/', '', (string) $daily->remarks) ?? '');
                $daily->save();
            }

            $mark->delete();
        }
    }

    public function balance(string $employeeId, int $typeId, ?int $year = null): array
    {
        return $this->balances->balance($employeeId, $typeId, $year);
    }

    public function balancesFor(string $employeeId, ?int $year = null): array
    {
        return $this->balances->balanceForAllTypes($employeeId, $year);
    }

    public function estimateDays(string $employeeId, int $typeId, string $from, string $to, bool $half = false): float
    {
        return $this->balances->days($employeeId, $typeId, $from, $to, $half);
    }

    public function nextApprovalStage(LeaveRequest $leave): ?string
    {
        $workflow = LeaveRequestWorkflow::query()->where('leave_request_id', $leave->id)->first();
        if ($workflow === null) {
            $type = LeaveType::find($leave->leave_type_id);
            $steps = $type ? $this->balances->settings($type)['approval_steps'] : ['HR'];
            $index = (int) LeaveApprovalAction::query()->where('leave_request_id', $leave->id)->where('decision', 'Approved')->count();

            return $steps[$index] ?? null;
        }

        return $workflow->steps[$workflow->current_stage] ?? null;
    }

    public function approvalHistory(int $leaveId): array
    {
        return LeaveApprovalAction::with('actor:id,name')
            ->where('leave_request_id', $leaveId)
            ->orderBy('stage_index')
            ->get()
            ->map(fn (LeaveApprovalAction $a): array => [
                'stage' => $a->stage_name,
                'stage_index' => $a->stage_index,
                'decision' => $a->decision,
                'actor' => $a->actor?->name,
                'comments' => $a->comments,
                'decided_at' => $a->decided_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function assertNoOverlap(string $employeeId, string $from, string $to, ?int $ignoreId = null): void
    {
        $q = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['Pending', 'Approved'])
            ->where(function ($g) use ($from, $to) {
                $g->whereBetween('from_date', [$from, $to])
                    ->orWhereBetween('to_date', [$from, $to])
                    ->orWhere(fn ($h) => $h->where('from_date', '<=', $from)->where('to_date', '>=', $to));
            });
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages(['from_date' => 'This application overlaps leave that is already pending or approved for the same employee.']);
        }
    }
}

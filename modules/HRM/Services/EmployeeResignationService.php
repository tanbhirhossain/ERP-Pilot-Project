<?php

namespace Modules\HRM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeResignation;

final class EmployeeResignationService
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return EmployeeResignation::query()->with('employee:id,employee_id,full_name')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), function ($q) use ($filters): void {
                $needle = '%'.addcslashes(trim($filters['search']), '%_').'%';
                $q->where(fn ($nested) => $nested->where('employee_id', 'like', $needle)->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', $needle)));
            })
            ->orderByDesc('id')->paginate(min(100, max(1, $perPage)))->withQueryString();
    }

    public function request(array $data, ?int $actorId): EmployeeResignation
    {
        if (EmployeeResignation::query()->where('employee_id', $data['employee_id'])->where('status', 'Pending')->exists()) {
            throw new \RuntimeException('This employee already has a pending resignation request.');
        }

        return EmployeeResignation::query()->create($data + ['status' => 'Pending', 'requested_by' => $actorId]);
    }

    public function __construct(private readonly ClearanceService $clearance) {}

    public function decide(EmployeeResignation $resignation, string $decision, ?int $actorId, ?string $note = null): EmployeeResignation
    {
        if (! in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new \InvalidArgumentException('Invalid resignation decision.');
        }

        return DB::transaction(function () use ($resignation, $decision, $actorId, $note): EmployeeResignation {
            $record = EmployeeResignation::query()->lockForUpdate()->findOrFail($resignation->id);
            if ($record->status !== 'Pending') {
                throw new \RuntimeException('Only pending resignation requests may be decided.');
            }
            $record->update(['status' => $decision, 'approved_by' => $actorId, 'approved_at' => now(), 'decision_note' => $note]);

            /*
             * An approved resignation opens the exit checklist. `ClearanceService::seedForResignation()`
             * has existed all along and nothing ever called it, so in production the Clearance screen —
             * and the "is this employee fully cleared?" question the final settlement depends on — had
             * no rows to show and no answer to give. Seeding here, inside the same transaction as the
             * approval, means the checklist cannot be missing for somebody whose last day is already
             * agreed. A rejection seeds nothing: the employee is staying.
             */
            if ($decision === 'Approved') {
                $this->clearance->seedForResignation($record->employee_id, $record->id);
                $this->endEmployment($record);
            }

            return $record->refresh();
        });
    }

    /**
     * Make the employee record agree with the resignation that was just approved.
     *
     * Approving a resignation never wrote `employees.status`. The employee stayed `Active` for ever:
     * HR screens said "Active" about somebody whose last day had passed, the directory listed them as
     * current staff, and the only place that knew better was payroll, which excludes them by a
     * separate `NOT EXISTS` on `employee_resignations`. Two sources of truth, and the visible one was
     * wrong. The correction is to write the record here, in the same transaction as the approval, so
     * the two cannot disagree.
     *
     * **When** it is written is the whole subtlety, and it is deliberately *not* "now":
     *
     *   - Approval happens before the last working day, not on it. A three-month notice period means
     *     ninety days in which this person is still employed, still rostered, still paid.
     *     Flipping `is_active` on approval day would remove them from payroll at once and they would
     *     stop being paid for the notice period they are still working. That is a payroll error, not
     *     a tidy-up, so the flag only moves on the day it is true.
     *   - The status itself is written on approval, because it is a *label* read by screens, and HR
     *     wants to see "Resigned, leaving 20 January" from the moment it is agreed. Payroll does not
     *     read the label alone, it reads {@see Employee::hasLeftOnOrBefore()} which compares the date —
     *     so a future-dated leaver is still paid, and still dockable, until their last day passes.
     *
     * A `last_working_date` that has already gone, or is today, is settled immediately: there is no
     * notice period left to protect.
     */
    private function endEmployment(EmployeeResignation $resignation): void
    {
        $employee = Employee::query()->where('employee_id', $resignation->employee_id)->lockForUpdate()->first();

        if ($employee === null) {
            return;
        }

        $lastWorkingDay = $resignation->last_working_date instanceof \DateTimeInterface
            ? Carbon::instance($resignation->last_working_date)->toDateString()
            : ($resignation->last_working_date !== null ? Carbon::parse($resignation->last_working_date)->toDateString() : null);

        $updates = ['status' => 'Resigned'];

        // Only switch the employment off once the last day has actually arrived. Before that the
        // person still works here and payroll must keep paying them.
        if ($lastWorkingDay === null || $lastWorkingDay <= now()->toDateString()) {
            $updates['is_active'] = false;
        }

        $employee->update($updates);

        // A last day that has arrived ends access in the same transaction as
        // the employment: tokens, sessions and pushes die with the approval.
        if ($employee->user !== null && $employee->hasLeftOnOrBefore(now()->toDateString())) {
            app(EmployeeLoginService::class)->revokeAccess($employee->user);
        }
    }
}

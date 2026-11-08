<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\ClearanceTask;

class ClearanceService
{
    public const DEFAULT_DEPARTMENTS = ['HR', 'IT', 'Admin', 'Finance', 'Payroll'];

    public function seedForResignation(string $employeeId, int $resignationId, ?array $departments = null): void
    {
        DB::transaction(function () use ($employeeId, $resignationId, $departments) {
            if (ClearanceTask::where('resignation_id', $resignationId)->exists()) {
                return;
            }
            $depts = $departments ?? self::DEFAULT_DEPARTMENTS;
            $tasks = [
                'HR' => ['Exit interview', 'Final attendance verification'],
                'IT' => ['Collect laptop/mobile/SIM', 'Revoke ERP/email access'],
                'Admin' => ['Collect ID card/uniform/locker'],
                'Finance' => ['Asset recovery check', 'Advance/loan reconciliation'],
                'Payroll' => ['Gratuity & encashment calculation', 'PF settlement flag'],
            ];
            foreach ($depts as $dept) {
                foreach (($tasks[$dept] ?? ["{$dept} clearance"]) as $t) {
                    ClearanceTask::create(['employee_id' => $employeeId, 'resignation_id' => $resignationId, 'department' => $dept, 'task_name' => $t]);
                }
            }
        });
    }

    public function clear(int $taskId, string $status, ?string $remarks = null): ClearanceTask
    {
        return DB::transaction(function () use ($taskId, $status, $remarks) {
            if (! in_array($status, ['Cleared', 'Rejected', 'Waived'], true)) {
                throw new \InvalidArgumentException('Invalid status');
            }
            $t = ClearanceTask::findOrFail($taskId);
            $t->update(['status' => $status, 'remarks' => $remarks, 'cleared_by' => auth()->id(), 'cleared_at' => now()]);

            return $t->fresh();
        });
    }

    public function isFullyCleared(string $employeeId, ?int $resignationId = null): bool
    {
        $q = ClearanceTask::where('employee_id', $employeeId)->whereNotIn('status', ['Cleared', 'Waived']);
        if ($resignationId) {
            $q->where('resignation_id', $resignationId);
        }

        return ! $q->exists();
    }

    public function summary(string $employeeId, ?int $resignationId = null): array
    {
        $tasks = ClearanceTask::where('employee_id', $employeeId)->when($resignationId, fn ($q) => $q->where('resignation_id', $resignationId))->get();

        return ['total' => $tasks->count(), 'cleared' => $tasks->where('status', 'Cleared')->count(), 'pending' => $tasks->where('status', 'Pending')->count(), 'tasks' => $tasks->toArray(), 'fully_cleared' => $tasks->isNotEmpty() && $tasks->whereNotIn('status', ['Cleared', 'Waived'])->isEmpty()];
    }
}

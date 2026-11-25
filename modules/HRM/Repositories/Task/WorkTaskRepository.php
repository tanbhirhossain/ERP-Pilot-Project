<?php

namespace Modules\HRM\Repositories\Task;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Interfaces\Task\WorkTaskRepositoryInterface;
use Modules\HRM\Models\Task\WorkTask;

class WorkTaskRepository implements WorkTaskRepositoryInterface
{
    public function priorities(): array
    {
        return WorkTask::PRIORITIES;
    }

    public function assigneeMoves(): array
    {
        return WorkTask::ASSIGNEE_MOVES;
    }

    public function create(array $data): WorkTask
    {
        return DB::transaction(fn () => WorkTask::create($data));
    }

    public function update(WorkTask $task, array $data): WorkTask
    {
        return DB::transaction(function () use ($task, $data) {
            $task->fill($data);
            $task->save();

            return $task;
        });
    }

    public function find(int $id): ?WorkTask
    {
        return WorkTask::find($id);
    }

    public function findDetailed(int $id, bool $withVerifier = false): ?WorkTask
    {
        $with = ['employee:employee_id,full_name,first_name,last_name', 'assigner:id,name'];

        if ($withVerifier) {
            $with[] = 'verifier:id,name';
        }

        return WorkTask::with($with)->find($id);
    }

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = WorkTask::where('employee_id', $code)
            ->with(['assigner:id,name'])
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function paginateAssignedBy(int $userId, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = WorkTask::where('assigned_by', $userId)
            ->with(['employee:employee_id,full_name,first_name,last_name'])
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function paginateForCodes(?array $codes, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = WorkTask::with(['employee:employee_id,full_name,first_name,last_name', 'assigner:id,name'])
            ->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}

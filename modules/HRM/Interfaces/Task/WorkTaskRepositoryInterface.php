<?php

namespace Modules\HRM\Interfaces\Task;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Models\Task\WorkTask;

interface WorkTaskRepositoryInterface
{
    public function priorities(): array;

    /** Assignee lanes: status → statuses it may move to. */
    public function assigneeMoves(): array;

    public function create(array $data): WorkTask;

    public function update(WorkTask $task, array $data): WorkTask;

    public function find(int $id): ?WorkTask;

    public function findDetailed(int $id, bool $withVerifier = false): ?WorkTask;

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator;

    public function paginateAssignedBy(int $userId, ?string $status, int $perPage): LengthAwarePaginator;

    /** @param list<string>|null $codes null = whole organisation */
    public function paginateForCodes(?array $codes, ?string $status, int $perPage): LengthAwarePaginator;
}

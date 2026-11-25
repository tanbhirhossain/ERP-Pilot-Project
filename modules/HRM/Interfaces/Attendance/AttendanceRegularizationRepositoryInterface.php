<?php

namespace Modules\HRM\Interfaces\Attendance;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Models\AttendanceRegularization;

interface AttendanceRegularizationRepositoryInterface
{
    /** The catalogue the apply form offers. */
    public function types(): array;

    public function find(int $id): ?AttendanceRegularization;

    public function findForEmployee(int $id, string $code, bool $withReviewer = false): ?AttendanceRegularization;

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator;

    public function pendingCountForEmployee(string $code): int;
}

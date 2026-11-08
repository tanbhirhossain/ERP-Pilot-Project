<?php

namespace Modules\HRM\Interfaces\Attendance;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Attendance\AttendanceLog;

interface AttendanceLogRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): AttendanceLog;

    public function unprocessed(int $limit = 500, ?string $from = null, ?string $to = null): Collection;

    public function unprocessedCount(?string $from = null, ?string $to = null): int;

    public function orphanCount(?string $from = null, ?string $to = null): int;

    public function create(array $data): AttendanceLog;

    public function createMany(array $rows): int;

    public function markProcessed(array $ids): void;

    public function delete(AttendanceLog $log): void;
}

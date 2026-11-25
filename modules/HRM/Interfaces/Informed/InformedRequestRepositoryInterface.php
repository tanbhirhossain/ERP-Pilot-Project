<?php

namespace Modules\HRM\Interfaces\Informed;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Informed\InformedRequest;

interface InformedRequestRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): InformedRequest;

    public function create(array $data): InformedRequest;

    public function update(InformedRequest $r, array $data): InformedRequest;

    public function delete(InformedRequest $r): void;
    public function find(int $id): ?InformedRequest;
    public function findForEmployee(int $id, string $code): ?InformedRequest;
    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator;
    public function pendingCountForEmployee(string $code): int;
}

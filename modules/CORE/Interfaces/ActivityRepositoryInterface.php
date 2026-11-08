<?php

namespace Modules\CORE\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Models\Activity;
use Modules\CORE\Support\DataTableQuery;

interface ActivityRepositoryInterface
{
    /**
     * One page of audit entries — never eager-loads the heavy payloads.
     */
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    /**
     * Fully filtered query for PDF / Excel audit exports.
     */
    public function exportBuilder(DataTableQuery $dt): Builder;

    /**
     * Full audit entry including properties + attribute changes + relations.
     */
    public function findWithDetails(int|string $id): Activity;

    /**
     * Distinct log names present in the audit trail (audit filters).
     *
     * @return Collection<int, string>
     */
    public function logNames(): Collection;

    /**
     * Distinct causers for the searchable "who" filter.
     *
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    public function causerOptions(?string $search = null, int $limit = 25): Collection;

    /**
     * Count of audit entries written since the given timestamp.
     */
    public function countSince(\DateTimeInterface $since): int;
}

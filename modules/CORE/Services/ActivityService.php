<?php

namespace Modules\CORE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Interfaces\ActivityRepositoryInterface;
use Modules\CORE\Models\Activity;
use Modules\CORE\Support\DataTableQuery;

/**
 * Read-side use cases for the JCI audit trail.
 */
class ActivityService
{
    public function __construct(
        private readonly ActivityRepositoryInterface $activities,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->activities->paginate($dt);
    }

    public function findWithDetails(int|string $id): Activity
    {
        return $this->activities->findWithDetails($id);
    }

    /**
     * @return Collection<int, string>
     */
    public function logNames(): Collection
    {
        return $this->activities->logNames();
    }

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    public function causerOptions(?string $search = null, int $limit = 25): Collection
    {
        return $this->activities->causerOptions($search, $limit);
    }

    /**
     * @return list<string>
     */
    public function subjectTypeOptions(): array
    {
        return ['user', 'role', 'permission', 'activity'];
    }
}

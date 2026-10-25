<?php

namespace Modules\CORE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Interfaces\ActivityRepositoryInterface;
use Modules\CORE\Models\Activity;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class ActivityRepository implements ActivityRepositoryInterface
{
    use BuildsDatatableQueries;

    /**
     * Maps the public "subject_type" filter slugs to concrete model classes.
     *
     * @var array<string, class-string>
     */
    private const SUBJECT_TYPES = [
        'user' => User::class,
        'role' => Role::class,
        'permission' => Permission::class,
        'activity' => Activity::class,
    ];

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->query($dt)->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        // Exports may include the request-context payload (IP etc.), which the
        // lightweight list select intentionally omits.
        return $this->query($dt)->addSelect('properties');
    }

    public function findWithDetails(int|string $id): Activity
    {
        return Activity::query()->with(['causer', 'subject'])->findOrFail($id);
    }

    public function logNames(): Collection
    {
        return Activity::query()
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->values();
    }

    public function causerOptions(?string $search = null, int $limit = 25): Collection
    {
        return User::query()
            ->when($search, function (Builder $query, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $query->where(function (Builder $group) use ($needle): void {
                    $group->where('name', 'like', $needle)->orWhere('email', 'like', $needle);
                });
            })
            ->whereIn('id', Activity::query()
                ->select('causer_id')
                ->distinct()
                ->where('causer_type', User::class)
                ->whereNotNull('causer_id'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
                'description' => $user->email,
            ])
            ->values();
    }

    public function countSince(\DateTimeInterface $since): int
    {
        return Activity::query()->where('created_at', '>=', $since)->count();
    }

    private function query(DataTableQuery $dt): Builder
    {
        $query = Activity::query()
            ->forList()
            ->with(['causer:id,name,email', 'subject']);

        $this->applyDataTable(
            $query,
            $dt,
            ['description', 'event'],
            ['id' => 'id', 'created_at' => 'created_at', 'event' => 'event', 'log_name' => 'log_name'],
            [
                'log_name' => fn (Builder $query, string $value) => $query->where('log_name', $value),
                'causer' => function (Builder $query, string $value): void {
                    $query->where('causer_type', User::class)->where('causer_id', (int) $value);
                },
                'subject_type' => function (Builder $query, string $value): void {
                    if (isset(self::SUBJECT_TYPES[$value])) {
                        $query->where('subject_type', self::SUBJECT_TYPES[$value]);
                    }
                },
                'date_from' => fn (Builder $query, string $value) => $query->whereDate('created_at', '>=', $value),
                'date_to' => fn (Builder $query, string $value) => $query->whereDate('created_at', '<=', $value),
            ]
        );

        return $query;
    }
}

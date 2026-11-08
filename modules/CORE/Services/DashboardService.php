<?php

namespace Modules\CORE\Services;

use Illuminate\Support\Facades\Cache;
use Modules\CORE\Interfaces\ActivityRepositoryInterface;
use Modules\CORE\Models\Activity;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;

/**
 * Aggregations for the admin dashboard. Counts are cached briefly so the
 * dashboard stays instant even when the audit table is enormous.
 */
class DashboardService
{
    public function __construct(
        private readonly ActivityRepositoryInterface $activities,
    ) {}

    /**
     * @return array{users: int, verified_users: int, roles: int, permissions: int, audit_events_24h: int}
     */
    public function stats(): array
    {
        return Cache::remember('core.dashboard.stats', now()->addMinute(), function (): array {
            return [
                'users' => User::query()->count(),
                'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
                'roles' => Role::query()->count(),
                'permissions' => Permission::query()->count(),
                'audit_events_24h' => $this->activities->countSince(now()->subDay()),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivities(int $limit = 8): array
    {
        return Activity::query()
            ->forList()
            ->with('causer:id,name,email')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($activity) => $activity->toTableRow())
            ->all();
    }

    /**
     * @return list<array{name: string, users_count: int}>
     */
    public function roleDistribution(int $limit = 6): array
    {
        return Role::query()
            ->withCount('users as users_count')
            ->orderByDesc('users_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Role $role): array => [
                'name' => $role->name,
                'users_count' => (int) $role->users_count,
            ])
            ->all();
    }
}

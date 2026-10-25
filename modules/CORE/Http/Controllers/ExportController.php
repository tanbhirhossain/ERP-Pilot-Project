<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CORE\Exports\ActivitiesExport;
use Modules\CORE\Exports\PermissionsExport;
use Modules\CORE\Exports\RolesExport;
use Modules\CORE\Exports\UsersExport;
use Modules\CORE\Interfaces\ActivityRepositoryInterface;
use Modules\CORE\Interfaces\PermissionRepositoryInterface;
use Modules\CORE\Interfaces\RoleRepositoryInterface;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\CORE\Services\ExportService;
use Modules\CORE\Support\DataTableQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the *current DataTable view* (search + filters + sort) to Excel or
 * PDF. Print is handled client-side by the DataTable component itself.
 *
 * Every export requires the same permission as viewing the underlying list —
 * you cannot export what you are not allowed to see.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exports,
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly ActivityRepositoryInterface $activities,
    ) {}

    public function __invoke(Request $request, string $resource, string $format): Response
    {
        abort_unless(in_array($resource, ['users', 'roles', 'permissions', 'activities'], true), 404);
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);

        $permission = match ($resource) {
            'users' => 'users.view',
            'roles' => 'roles.view',
            'permissions' => 'permissions.view',
            'activities' => 'audit.view',
        };

        abort_unless($request->user()?->can($permission), 403);

        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: $this->allowedSorts($resource),
            allowedFilters: $this->allowedFilters($resource),
        );

        $maxRows = (int) config($format === 'excel' ? 'core.exports.excel_max_rows' : 'core.exports.pdf_max_rows');

        if ($format === 'excel') {
            return $this->excel($resource, $dt, $maxRows);
        }

        return $this->pdf($resource, $dt, $maxRows);
    }

    private function excel(string $resource, DataTableQuery $dt, int $maxRows): Response
    {
        $export = match ($resource) {
            'users' => new UsersExport($this->users->exportBuilder($dt), $maxRows),
            'roles' => new RolesExport($this->roles->exportBuilder($dt), $maxRows),
            'permissions' => new PermissionsExport($this->permissions->exportBuilder($dt), $maxRows),
            'activities' => new ActivitiesExport($this->activities->exportBuilder($dt), $maxRows),
        };

        return $this->exports->excel($export, "core-{$resource}");
    }

    private function pdf(string $resource, DataTableQuery $dt, int $maxRows): Response
    {
        $query = match ($resource) {
            'users' => $this->users->exportBuilder($dt),
            'roles' => $this->roles->exportBuilder($dt),
            'permissions' => $this->permissions->exportBuilder($dt),
            'activities' => $this->activities->exportBuilder($dt),
        };

        [$headings, $rows] = match ($resource) {
            'users' => [
                ['#', 'Name', 'Email', 'Roles', 'Status', 'Joined'],
                $query->limit($maxRows)->get()->map(fn ($user) => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->roles->pluck('name')->implode(', '),
                    $user->email_verified_at ? 'Verified' : 'Unverified',
                    $user->created_at?->format('Y-m-d H:i'),
                ])->all(),
            ],
            'roles' => [
                ['#', 'Role', 'Members', 'Permissions', 'Created'],
                $query->limit($maxRows)->get()->map(fn ($role) => [
                    $role->id,
                    $role->name,
                    (int) ($role->users_count ?? 0),
                    (int) ($role->permissions_count ?? $role->permissions()->count()),
                    $role->created_at?->format('Y-m-d H:i'),
                ])->all(),
            ],
            'permissions' => [
                ['#', 'Permission', 'Group', 'Guard', 'Roles', 'Users', 'Created'],
                $query->limit($maxRows)->get()->map(fn ($permission) => [
                    $permission->id,
                    $permission->name,
                    $permission->group,
                    $permission->guard_name,
                    (int) ($permission->roles_count ?? 0),
                    (int) ($permission->users_count ?? 0),
                    $permission->created_at?->format('Y-m-d H:i'),
                ])->all(),
            ],
            'activities' => [
                ['#', 'Timestamp', 'Log', 'Event', 'Description', 'Causer', 'Subject', 'IP'],
                $query->limit($maxRows)->get()->map(fn ($activity) => [
                    $activity->id,
                    $activity->created_at?->format('Y-m-d H:i:s'),
                    $activity->log_name,
                    $activity->event,
                    $activity->description,
                    $activity->causer?->name ?? 'System',
                    $activity->subject_type !== null
                        ? class_basename($activity->subject_type).' #'.($activity->subject_id ?? '')
                        : '—',
                    $activity->properties?->get('ip') ?? '—',
                ])->all(),
            ],
        };

        return $this->exports->pdf(
            title: ucfirst($resource).' — current view',
            headings: $headings,
            rows: $rows,
            filename: "core-{$resource}",
            meta: $this->meta($dt),
        );
    }

    /**
     * @return list<string>
     */
    private function allowedSorts(string $resource): array
    {
        return match ($resource) {
            'users' => ['id', 'name', 'email', 'verified', 'created_at'],
            'roles' => ['id', 'name', 'created_at'],
            'permissions' => ['id', 'name', 'guard_name', 'created_at'],
            'activities' => ['id', 'created_at', 'event', 'log_name'],
        };
    }

    /**
     * @return list<string>
     */
    private function allowedFilters(string $resource): array
    {
        return match ($resource) {
            'users' => ['role', 'verified'],
            'roles' => ['permission'],
            'permissions' => ['group'],
            'activities' => ['log_name', 'causer', 'subject_type', 'date_from', 'date_to'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function meta(DataTableQuery $dt): array
    {
        $meta = [];

        if ($dt->search !== null) {
            $meta['Search'] = '"'.$dt->search.'"';
        }

        foreach ($dt->filters as $key => $value) {
            $meta['Filter · '.$key] = $value;
        }

        $meta['Sort'] = ($dt->sort ?? $dt->defaultSort).' '.$dt->direction;

        return $meta;
    }
}

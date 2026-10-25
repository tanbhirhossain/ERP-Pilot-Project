<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\Permission;
use Modules\CORE\Requests\StorePermissionRequest;
use Modules\CORE\Requests\UpdatePermissionRequest;
use Modules\CORE\Services\PermissionService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: ['id', 'name', 'guard_name', 'created_at'],
            allowedFilters: ['group'],
        );

        return Inertia::render('CORE::Permissions/Index', [
            'permissions' => PaginatedResource::from(
                $this->permissions->paginate($dt)->through(fn (Permission $permission): array => $this->row($permission))
            ),
            'filterDefs' => [
                [
                    'key' => 'group',
                    'label' => 'Group',
                    'kind' => 'select',
                    'options' => $this->permissions->groups()
                        ->map(fn (string $group): array => ['value' => $group, 'label' => $group])
                        ->values()
                        ->all(),
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CORE::Permissions/Create');
    }

    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $this->permissions->create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        return redirect()
            ->route('core.permissions.index')
            ->with('success', 'Permission created successfully.');
    }

    public function edit(Permission $permission): Response
    {
        $permission->load('roles:id,name,guard_name');

        return Inertia::render('CORE::Permissions/Edit', [
            'permission' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'group' => $permission->group,
                'guard_name' => $permission->guard_name,
                'roles' => $permission->roles->map(fn ($role): array => ['id' => $role->id, 'name' => $role->name])->values()->all(),
                'created_at' => $permission->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->permissions->update($permission, [
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        return redirect()
            ->route('core.permissions.index')
            ->with('success', 'Permission updated successfully.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        try {
            $this->permissions->delete($permission);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('core.permissions.index')
            ->with('success', 'Permission deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'group' => $permission->group,
            'guard_name' => $permission->guard_name,
            'roles_count' => (int) ($permission->roles_count ?? 0),
            'users_count' => (int) ($permission->users_count ?? 0),
            'created_at' => $permission->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\Role;
use Modules\CORE\Requests\StoreRoleRequest;
use Modules\CORE\Requests\UpdateRoleRequest;
use Modules\CORE\Services\PermissionService;
use Modules\CORE\Services\RoleService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: ['id', 'name', 'created_at'],
            allowedFilters: ['permission'],
        );

        $permissionOptions = $this->permissions->options(null, 500);

        return Inertia::render('CORE::Roles/Index', [
            'roles' => PaginatedResource::from(
                $this->roles->paginate($dt)->through(fn (Role $role): array => $this->row($role))
            ),
            'filterDefs' => [
                [
                    'key' => 'permission',
                    'label' => 'Permission',
                    'kind' => 'select',
                    'options' => $permissionOptions,
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CORE::Roles/Create', [
            'permissionOptions' => $this->permissions->options(null, 500),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->roles->create(
            ['name' => $request->validated('name'), 'guard_name' => 'web'],
            $this->permissionIds($request->validated()),
        );

        return redirect()
            ->route('core.roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function edit(Role $role): Response
    {
        $role->load('permissions:id,name,guard_name');

        return Inertia::render('CORE::Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => (int) $role->users_count,
                'permissions' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'created_at' => $role->created_at?->toIso8601String(),
            ],
            'permissionOptions' => $this->permissions->options(null, 500),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update(
            $role,
            ['name' => $request->validated('name'), 'guard_name' => 'web'],
            $this->permissionIds($request->validated()),
        );

        return redirect()
            ->route('core.roles.index')
            ->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        try {
            $this->roles->delete($role);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('core.roles.index')
            ->with('success', 'Role deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function permissionIds(array $validated): array
    {
        return array_values(array_map('intval', $validated['permissions'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => (int) ($role->users_count ?? 0),
            'permissions_count' => (int) ($role->permissions_count ?? 0),
            'created_at' => $role->created_at?->toIso8601String(),
        ];
    }
}

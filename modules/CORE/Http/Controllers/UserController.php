<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\User;
use Modules\CORE\Requests\StoreUserRequest;
use Modules\CORE\Requests\UpdateUserRequest;
use Modules\CORE\Services\RoleService;
use Modules\CORE\Services\UserService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly RoleService $roles,
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: ['id', 'name', 'email', 'verified', 'created_at'],
            allowedFilters: ['role', 'verified'],
        );

        return Inertia::render('CORE::Users/Index', [
            'users' => PaginatedResource::from(
                $this->users->paginate($dt)->through(fn (User $user): array => $this->row($user))
            ),
            'roleOptions' => $this->roles->options(null, 200),
            'filterDefs' => [
                [
                    'key' => 'role',
                    'label' => 'Role',
                    'kind' => 'select',
                    'options' => $this->roles->options(null, 200),
                ],
                [
                    'key' => 'verified',
                    'label' => 'Status',
                    'kind' => 'select',
                    'options' => [
                        ['value' => '1', 'label' => 'Verified'],
                        ['value' => '0', 'label' => 'Unverified'],
                    ],
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CORE::Users/Create', [
            'roleOptions' => $this->roles->options(null, 200),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->users->create(
            $this->userData($request->validated()),
            $this->roleIds($request->validated()),
        );

        return redirect()
            ->route('core.users.index')
            ->with('success', 'User created successfully — the account is ready to use.');
    }

    public function edit(User $user): Response
    {
        $user->load('roles:id,name');

        return Inertia::render('CORE::Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'verified' => (bool) $user->email_verified_at,
                'roles' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'roleOptions' => $this->roles->options(null, 200),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->users->update(
            $user,
            $this->userData($request->validated(), $user),
            $this->roleIds($request->validated()),
        );

        return redirect()
            ->route('core.users.index')
            ->with('success', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->delete($user, $request->user());
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('core.users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function userData(array $validated, ?User $user = null): array
    {
        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if (array_key_exists('password', $validated) && $validated['password'] !== null && $validated['password'] !== '') {
            $data['password'] = $validated['password'];
        }

        if (array_key_exists('verified', $validated)) {
            $data['email_verified_at'] = $validated['verified'] ? now() : null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function roleIds(array $validated): array
    {
        return array_values(array_map('intval', $validated['roles'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'verified' => (bool) $user->email_verified_at,
            'roles' => $user->roles
                ->map(fn ($role): array => ['id' => $role->id, 'name' => $role->name])
                ->values()
                ->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}

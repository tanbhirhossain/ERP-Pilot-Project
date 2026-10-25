<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\User;
use Modules\CORE\Services\RoleService;
use Modules\CORE\Services\UserService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

/**
 * Design System — the living catalogue of the kit's icons and components.
 *
 * The demo table is fed with real paginated users (through the same
 * service/repository stack every CRUD screen uses), so search, sort,
 * filter, export and print can all be exercised from this one page.
 */
class UiKitController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly RoleService $roles,
    ) {}

    public function __invoke(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest(
            $request,
            allowedSorts: ['id', 'name', 'email', 'verified', 'created_at'],
            allowedFilters: ['role', 'verified'],
        );

        return Inertia::render('CORE::UiKit', [
            'demoUsers' => PaginatedResource::from(
                $this->users->paginate($dt)->through(fn (User $user): array => $this->row($user))
            ),
            'roleOptions' => $this->roles->options(null, 50),
            'filterDefs' => [
                [
                    'key' => 'role',
                    'label' => 'Role',
                    'kind' => 'select',
                    'options' => $this->roles->options(null, 50),
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
            'environment' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'modules' => $this->modules(),
            ],
        ]);
    }

    /**
     * Directory names under `modules/` — shown on the Design System page.
     *
     * @return list<string>
     */
    private function modules(): array
    {
        $entries = scandir(base_path('modules')) ?: [];

        return array_values(array_filter(
            $entries,
            fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
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

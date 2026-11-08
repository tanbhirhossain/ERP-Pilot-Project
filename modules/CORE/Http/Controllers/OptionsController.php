<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\CORE\Services\ActivityService;
use Modules\CORE\Services\UserService;
use Modules\CORE\Support\OptionAccess;

/**
 * Lightweight JSON endpoint powering the SearchableSelect dropdown when its
 * options live in a table that is too large to ship to the browser.
 *
 * This was already the one controller authorising per type, but it did so with three separate
 * `abort_unless()` calls. It now delegates to the shared `OptionAccess` gate so all six option
 * controllers enforce the same rule the same way, and so a 403 is JSON: `abort()` renders an HTML
 * error page unless the request sent `Accept: application/json`, and `SearchableSelect` calls these
 * endpoints with a bare `fetch()`.
 *
 * Note that no template currently requests any of these three types — the activity log renders its
 * filters from props rather than from this endpoint. They are kept because the route is public API
 * surface and removing it would be a breaking change for any caller outside this repository.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'users' => [
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.update', 'permissions.view',
        ],
        'causers' => ['audit.view', 'users.view'],
        'log-names' => ['audit.view', 'users.view'],
    ];

    public function __construct(
        private readonly UserService $users,
        private readonly ActivityService $activities,
    ) {}

    public function __invoke(Request $request, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) $request->query('search', ''));

        $items = match ($type) {
            'users' => $this->forUsers($search !== '' ? $search : null),
            'causers' => $this->forCausers($search !== '' ? $search : null),
            'log-names' => $this->forLogNames(),
        };

        return response()->json([
            'items' => $items,
        ]);
    }

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    private function forUsers(?string $search): Collection
    {
        return $this->users->options($search, 25);
    }

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    private function forCausers(?string $search): Collection
    {
        return $this->activities->causerOptions($search, 25);
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    private function forLogNames(): Collection
    {
        return $this->activities->logNames()
            ->map(fn (string $name): array => ['value' => $name, 'label' => ucfirst($name)])
            ->values();
    }
}

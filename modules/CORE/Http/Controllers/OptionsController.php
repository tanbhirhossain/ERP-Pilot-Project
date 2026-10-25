<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\CORE\Services\ActivityService;
use Modules\CORE\Services\UserService;

/**
 * Lightweight JSON endpoint powering the SearchableSelect dropdown when its
 * options live in a table that is too large to ship to the browser.
 */
class OptionsController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly ActivityService $activities,
    ) {}

    public function __invoke(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) $request->query('search', ''));

        $items = match ($type) {
            'users' => $this->forUsers($request, $search !== '' ? $search : null),
            'causers' => $this->forCausers($request, $search !== '' ? $search : null),
            'log-names' => $this->forLogNames($request),
            default => abort(404),
        };

        return response()->json([
            'items' => $items,
        ]);
    }

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    private function forUsers(Request $request, ?string $search): Collection
    {
        abort_unless($request->user()?->can('users.view'), 403);

        return $this->users->options($search, 25);
    }

    /**
     * @return Collection<int, array{value: int|string, label: string, description?: string}>
     */
    private function forCausers(Request $request, ?string $search): Collection
    {
        abort_unless($request->user()?->can('audit.view'), 403);

        return $this->activities->causerOptions($search, 25);
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    private function forLogNames(Request $request): Collection
    {
        abort_unless($request->user()?->can('audit.view'), 403);

        return $this->activities->logNames()
            ->map(fn (string $name): array => ['value' => $name, 'label' => ucfirst($name)])
            ->values();
    }
}

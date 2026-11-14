<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Services\ApiTokenService;

/**
 * F13 — self-service personal access tokens.
 *
 * Deliberately gated on `auth` alone, not on a permission: a token can only ever reach
 * what its owning user can reach, so letting every user mint their own is not an
 * escalation — it is the point. Requiring a Super Admin to issue tokens instead produces
 * shared "integration" accounts with someone else's ACL, which is the thing that actually
 * breaks auditing.
 *
 * The secret travels once, as flash data, and is `pull`ed by the page — refresh the
 * screen and it is gone, exactly as it should be. It is never logged, and it cannot be
 * read back: only a sha256 hash is stored.
 */
class ApiTokensController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function index(Request $request): Response
    {
        return Inertia::render('CORE::Developer/Tokens', [
            'tokens' => $this->tokens->listFor($request->user()),
            'presets' => ApiTokenService::allowedPresetNames(),
            'maxDays' => ApiTokenService::MAX_LIFETIME_DAYS,
            'issued' => session()->pull('api_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', ApiTokenService::allowedPresetNames())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.ApiTokenService::MAX_LIFETIME_DAYS],
        ]);

        $issued = $this->tokens->issue($request->user(), $data['name'], $data['abilities'] ?? [], $data['expires_in_days'] ?? null);

        return Redirect::route('core.developer.tokens.index')
            ->with('api_token', ['token' => $issued['token'], 'name' => $issued['name'], 'abilities' => $issued['abilities'], 'expires_at' => $issued['expires_at']])
            ->with('success', "Token “{$issued['name']}” created. Copy it now — it will not be shown again.");
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        // Scoped to the caller, so the id on its own is not a capability.
        if (! $this->tokens->revoke($request->user(), $token)) {
            return Redirect::back()->withErrors(['token' => 'No token with that id belongs to you.']);
        }

        return Redirect::route('core.developer.tokens.index')->with('success', 'Token revoked. Any request using it is now refused.');
    }
}

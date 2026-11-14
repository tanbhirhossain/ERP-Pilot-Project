<?php

namespace Modules\CORE\Services;

use Illuminate\Support\Collection;
use Modules\CORE\Models\User;

/**
 * F13 — issuing, listing and revoking personal access tokens.
 *
 * Two callers share this: the Developer → API Tokens screen (session-authenticated)
 * and `POST /api/v1/auth/token` (credentials exchanged for a token, for CI and
 * headless clients). Both must apply the same rules, so neither owns a copy.
 *
 * The secret is returned exactly once, from `issue()`, and never stored — Sanctum
 * keeps only its sha256 hash. Nothing here can read it back, which is why the screen
 * shows a one-time reveal instead of a "copy" button on old rows.
 */
final class ApiTokenService
{
    /** Beyond this, ask why a machine credential needs a year. */
    public const MAX_LIFETIME_DAYS = 365;

    /**
     * What a caller may ask for, and what it actually buys.
     *
     * Abilities only ever *narrow*: what a token may do is the intersection of these
     * and the roles the owning user holds, so stealing a token from an accountant
     * cannot mint an invoice-level credential. Presets are expanded at issue time
     * rather than interpreted per request, so a token's row states plainly what it
     * can reach — which is the only thing an auditor reviewing the list can act on.
     */
    public const PRESETS = [
        '*' => ['*'],
        'read' => ['sales:read', 'accounts:read'],
        'sales:read' => ['sales:read'],
        'accounts:read' => ['accounts:read'],
        // Write implies read: an integration that posts journals must be able to read
        // the accounts it is posting to, and a caller denied that just asks for two
        // tokens — which defeats the point of having one row to revoke.
        'accounts:write' => ['accounts:write', 'accounts:read'],
    ];

    public static function allowedPresetNames(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function expandAbilities(array $requested): array
    {
        if ($requested === [] || $requested === ['*']) {
            return ['*'];
        }

        $out = [];
        foreach ($requested as $name) {
            foreach (self::PRESETS[$name] ?? [$name] as $ability) {
                $out[$ability] = true;
            }
        }

        $out = array_keys($out);
        sort($out);

        return $out;
    }

    /**
     * @param  list<string>  $abilities
     * @return array{token: string, id: int, name: string, abilities: list<string>, expires_at: ?string}
     */
    public function issue(User $user, string $name, array $abilities = [], ?int $days = null): array
    {
        $abilities = self::expandAbilities($abilities);

        $expiresAt = $days === null
            ? null
            : now()->addDays(max(1, min($days, self::MAX_LIFETIME_DAYS)));

        $result = $user->createToken($name, $abilities, $expiresAt);

        // `plainTextToken` is the only place the secret exists. It arrives as
        // "id|secret"; the id is already returned separately, so hand back the whole
        // string as Sanctum intends it — that is what a client must send.
        return [
            'token' => $result->plainTextToken,
            'id' => $result->accessToken->id,
            'name' => $result->accessToken->name,
            'abilities' => $abilities,
            'expires_at' => $result->accessToken->expires_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listFor(User $user): Collection
    {
        return $user->tokens()->orderByDesc('created_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities ?? ['*'],
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Revoke by id, scoped to this user — the id alone is not a capability, so a
     * guessed id cannot cut someone else's integration loose.
     */
    public function revoke(User $user, int $tokenId): bool
    {
        return $user->tokens()->whereKey($tokenId)->delete() > 0;
    }
}

<?php

namespace Modules\CORE\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\User;
use Modules\CORE\Services\ApiTokenService;
use Modules\CORE\Support\Api\ApiEndpoint;

/**
 * F13 — the credentials-for-a-token exchange, plus "who am I as this token?".
 *
 * This is the only endpoint on the API that is not itself authenticated, and the only
 * one with a rate limit of its own: it is the login form wearing a bearer-token hat, so
 * it gets the same care (constant-time-ish failure, identical message whether the address
 * is unknown or the password is wrong) and the same throttle.
 */
class AuthTokenController extends Controller
{
    /**
     * A well-formed bcrypt string that matches nothing, so a request for an unknown
     * address costs the same as one for a known one. Without it, the gap between "no
     * such user" and "wrong password" is measurable and the endpoint becomes a
     * subscriber list.
     */
    private const TIMING_HASH = '$2y$13$Jf6FiowxVMQ4sWD4oaMcOOmilwfjKTjG/7VpPgv3pg9J0B.GaDOdq';

    public function __construct(private readonly ApiTokenService $tokens) {}

    #[ApiEndpoint(
        summary: 'Exchange credentials for a token',
        description: 'The secret is returned once — only its sha256 hash is stored, so it can never be read back. `abilities` narrows what this token may reach and can never widen what the owning user\'s roles allow. Bad credentials answer 401 with the same message whether the address is unknown or the password is wrong.',
        request: 'TokenIssued',
        response: 'TokenIssued',
        public: true,
    )]
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', ApiTokenService::allowedPresetNames())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.ApiTokenService::MAX_LIFETIME_DAYS],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null) {
            Hash::check($data['password'], self::TIMING_HASH);

            return $this->refused();
        }

        if (! Hash::check($data['password'], $user->password)) {
            return $this->refused();
        }

        $issued = $this->tokens->issue($user, $data['device_name'], $data['abilities'] ?? [], $data['expires_in_days'] ?? null);

        return response()->json([
            'token' => $issued['token'],
            'token_id' => $issued['id'],
            'abilities' => $issued['abilities'],
            'expires_at' => $issued['expires_at'],
            // Said once, because it is stored once-hashed and can never be shown again.
            'warning' => 'Store this token now — it cannot be retrieved later.',
        ], 201);
    }

    /** The caller's own identity, role-derived permissions and token abilities. */
    #[ApiEndpoint(
        summary: 'Who am I, and what may this token do',
        description: 'The owning user\'s effective permissions alongside this token\'s abilities. This is the answer to "my token works but returns 403": the token is almost always narrower than the user.',
        response: 'CallerIdentity',
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
            // `getAllPermissions` is Spatie's, so this is the *effective* answer — the
            // same set the route middleware will reach. A caller that sees a permission
            // listed here and is still refused has a narrow token, not a broken ACL.
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'token' => [
                'id' => method_exists($token, 'getKey') ? $token->getKey() : null,
                'name' => $token->name ?? 'session',
                'abilities' => $token->abilities ?? ['*'],
                'last_used_at' => $token->last_used_at ?? null,
            ],
        ]);
    }

    /** Revoke the token making the call. A browser session has no token to revoke. */
    #[ApiEndpoint(
        summary: 'Revoke the token making the call',
        description: 'A browser session has no token to revoke and gets 400 rather than a silent success. Revoking the token you are authenticated with ends the request\'s own authority — that is intended, not a bug.',
    )]
    public function revoke(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! method_exists($token, 'delete')) {
            abort(400, 'This request is authenticated by a browser session, which has no token to revoke.');
        }

        $token->delete();

        return response()->json(['revoked' => true]);
    }

    private function refused(): JsonResponse
    {
        return response()->json(['message' => 'These credentials do not match our records.'], 401);
    }
}

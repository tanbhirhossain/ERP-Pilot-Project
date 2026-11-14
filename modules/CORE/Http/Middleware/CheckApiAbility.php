<?php

namespace Modules\CORE\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F13 — enforce the route's required token ability, if it declares one.
 *
 * The permission middleware already decides what the *user* may do. This decides
 * what this *token* may do, which is the difference between "the accountant's role
 * allows journals" and "the accountant's CI token should not".
 *
 * The ability is a middleware parameter (`ability:sales:read` on the route) rather than
 * metadata, so `php artisan api:spec` can copy the exact string out of the route table
 * when it writes `x-required-ability` — documentation and enforcement read one source.
 *
 * A wildcard token (`*`) passes anything, and a session-authenticated request passes
 * everything because it has no token to speak for it — `TransientToken::can()` says
 * yes, which is what keeps the in-app API Reference page able to try the endpoints.
 */
class CheckApiAbility
{
    public function handle(Request $request, Closure $next, string $required): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->tokenCan($required)) {
            abort(403, "This token's abilities do not include [$required].");
        }

        return $next($request);
    }
}

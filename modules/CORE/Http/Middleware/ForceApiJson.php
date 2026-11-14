<?php

namespace Modules\CORE\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F13 — make the API answer in JSON whether or not the client asked nicely.
 *
 * Laravel decides between a redirect and a 401 by content negotiation, and its rule is
 * strict: `wantsJson()` says yes only when the *first* acceptable type mentions `json`.
 * So a client that sends no Accept header — or the wildcard Accept that curl sends by
 * default — gets `302 → /login` from the auth middleware instead of an error it can
 * read. That is the classic "works in Postman, fails in curl" report, and the redirect
 * is doubly bad: it leaks the login route and it swallows the real cause (expired
 * token, narrow token, revoked token) behind a status code no client expects.
 *
 * Only an absent or wildcard Accept is rewritten. A caller that named something specific
 * (`text/csv`, `application/xml`) meant it; overruling that would be this middleware
 * inventing a preference the client did not express.
 */
class ForceApiJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // A missing header, the curl default, and `application/*` all mean "no preference".
        $accept = trim((string) $request->header('Accept', ''));

        if (in_array($accept, ['', '*/*', 'application/*'], true)) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}

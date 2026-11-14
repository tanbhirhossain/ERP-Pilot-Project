<?php

namespace Modules\CORE\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\CORE\Support\Locale;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decide the UI language for the request — U19.
 *
 * Runs on the `web` group before `HandleInertiaRequests`, so the language the shell shares with the
 * front end and the `lang`/`dir` baked into the server-rendered `<html>` are the same value, resolved
 * from one place. A personal choice (session) wins; otherwise the company default; otherwise the
 * catalogue default.
 *
 * It is safe to run before the migration exists: `Locale::company()` guards on `Schema::hasTable`, so
 * a request made during `artisan migrate` simply resolves to the default instead of erroring.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->session()->get('app.locale');

        if (! Locale::isValid($code)) {
            $code = Locale::company();
        }

        app()->setLocale($code);

        return $next($request);
    }
}

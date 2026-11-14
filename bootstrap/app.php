<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Modules\CORE\Http\Middleware\CheckApiAbility;
use Modules\CORE\Http\Middleware\ForceApiJson;
use Modules\CORE\Http\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // SetLocale runs first so HandleInertiaRequests shares the language the
        // shell will render, and the two never disagree about the direction.
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // F13: the token-authenticated surface. This app has no root routes/api.php
        // — each module owns `Routes/api.php` — so the framework never wires the `api`
        // group's throttle for us (it does that only when `withRouting(api:)` is used)
        // and the group would otherwise carry nothing but SubstituteBindings. Prepend
        // the JSON default so a 401 is a 401 and not a redirect to the login form, and
        // append the limiter so a runaway integration costs requests, not database.
        $middleware->alias(['ability' => CheckApiAbility::class]);
        $middleware->api(prepend: [ForceApiJson::class]);
        $middleware->api(append: ['throttle:api']);

        // Runs on every response, including downloads and JSON, so security
        // headers cannot be missed by a route that skips the `web` group.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

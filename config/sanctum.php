<?php

/**
 * F13 — published Sanctum configuration, with the two values this ERP changes on
 * purpose spelled out and reasoned about.
 *
 * `guard` stays `web`: a request carrying no bearer token may still be answered by
 * the session, which is what lets the Developer → API Reference page call the same
 * `/api/v1` endpoints the tokens do. Anything else would fork the API surface into
 * "for browsers" and "for machines".
 *
 * `expiration` stays null because a *per-token* `expires_at` is set at issue time
 * instead (see ApiTokenService): a CI job legitimately wants a year, a contractor's
 * laptop should not have it. A global expiry would force everyone to the shortest
 * useful lifetime.
 *
 * `token_prefix` makes a leaked token identifiable in logs and to secret scanners
 * (`erp_…`). It costs nothing to verify because the prefix is baked into the secret
 * itself — `generateTokenString()` returns prefix + 40 random chars + a crc32b
 * checksum of the random part, and that whole string is what gets hashed and stored
 * and what the client sends back. The trailing checksum is there for *clients and
 * scanners* to recognise a well-formed token; Sanctum's own guard only checks the
 * `id|secret` shape before it does a hashed lookup, so nothing here weakens the
 * lookup path.
 */

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'erp_'),

    'prefix' => 'sanctum',

    'routes' => true,

    'last_used_at' => true,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];

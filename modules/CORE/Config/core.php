<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export limits
    |--------------------------------------------------------------------------
    |
    | Exports stream straight from the database so memory stays flat, but a
    | browser download still has to finish in a reasonable time. Excel exports
    | chunk through the query; PDF renders a fixed paper document so it gets a
    | stricter cap. Both limits can be overridden at runtime.
    |
    */
    'exports' => [
        'excel_max_rows' => (int) env('CORE_EXPORT_EXCEL_MAX_ROWS', 100000),
        'pdf_max_rows' => (int) env('CORE_EXPORT_PDF_MAX_ROWS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected roles
    |--------------------------------------------------------------------------
    |
    | Roles listed here can never be deleted from the ACL UI, guarding the
    | platform administrator role from accidental removal.
    |
    */
    'protected_roles' => ['Super Admin'],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | This is an internal ERP: accounts are provisioned by an administrator
    | from the Users screen, so public self-registration is OFF by default.
    | Turn it on only for a deployment that genuinely wants open sign-up.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | HTTP security headers
    |--------------------------------------------------------------------------
    |
    | Applied by App\Http\Middleware\SecurityHeaders. Every value can be
    | overridden per environment without a code change.
    |
    */

    'security_headers' => [
        'enabled' => (bool) env('CORE_SECURITY_HEADERS', true),
        'frame_options' => env('CORE_FRAME_OPTIONS', 'SAMEORIGIN'),
        'referrer_policy' => env('CORE_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        // interest-cohort is deliberately absent: FLoC was withdrawn and Chrome
        // no longer recognises the token, so it only produced an "Unrecognized
        // feature" console error without protecting anything.
        'permissions_policy' => env(
            'CORE_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), browsing-topics=()'
        ),

        // HSTS is only emitted on an already-secure request.
        'hsts_max_age' => (int) env('CORE_HSTS_MAX_AGE', 31536000),
        'hsts_include_subdomains' => (bool) env('CORE_HSTS_INCLUDE_SUBDOMAINS', true),
        'hsts_preload' => (bool) env('CORE_HSTS_PRELOAD', false),

        /*
         * script-src carries NO 'unsafe-inline' and no literal nonce. The
         * SecurityHeaders middleware generates a fresh nonce per request, hands
         * it to Vite (`useCspNonce`) and to Ziggy's `@routes` directive, then
         * appends `'nonce-...'` to this directive. Both emit inline scripts, so
         * without the nonce the whole SPA fails to boot.
         *
         * style-src keeps 'unsafe-inline': Vue pages bind element styles
         * (`:style="{ width: pct + '%' }"`) for progress bars and charts, and
         * CSP counts a style attribute as inline. A nonce would make browsers
         * ignore 'unsafe-inline' for style elements, so styles use no nonce.
         *
         * fonts.bunny.net is loaded by app.blade.php on every page; rsms.me is
         * loaded by Welcome.vue through @inertiaHead. Both the stylesheet and
         * the font files themselves need allowing, hence style-src AND font-src.
         */
        'content_security_policy' => env('CORE_CSP', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://rsms.me",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.bunny.net https://rsms.me",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
        ])),
        'csp_report_only' => (bool) env('CORE_CSP_REPORT_ONLY', false),
    ],

    'auth' => [
        'allow_registration' => (bool) env('CORE_ALLOW_REGISTRATION', false),
        // Role granted to a self-registered account when registration is enabled.
        // Left null on purpose: a new sign-up gets NO role and therefore no
        // module access until an administrator assigns one.
        'default_registration_role' => env('CORE_DEFAULT_REGISTRATION_ROLE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Super admin account
    |--------------------------------------------------------------------------
    |
    | Consumed by SuperAdminSeeder. Override per environment through the
    | CORE_SUPER_ADMIN_* variables; the defaults are the starter-kit
    | credentials used throughout the documentation and test suite.
    |
    */
    'super_admin' => [
        'name' => env('CORE_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('CORE_SUPER_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('CORE_SUPER_ADMIN_PASSWORD', 'password'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Published-credential guard
    |--------------------------------------------------------------------------
    |
    | This repository publishes `admin@example.com` / `password` in the config
    | above, in .env.example, in SuperAdminSeeder and — for three demo users
    | holding the Manager, Auditor and Staff roles — in CoreSeeder. Under
    | APP_ENV=production those strings are refused, both when a seeder tries to
    | write them and when a login tries to use them.
    |
    | See Modules\CORE\Support\PublishedCredentialGuard. Setting
    | CORE_BLOCK_PUBLISHED_CREDENTIALS=false disables the guard; the choice is
    | logged so it is at least visible in the audit trail.
    |
    */
    'security' => [
        'block_published_credentials' => env('CORE_BLOCK_PUBLISHED_CREDENTIALS', true),

        // Force APP_DEBUG off when APP_ENV=production, and log that it did. `.env.example` is the
        // local template and ships APP_DEBUG=true; a server handed that file instead of
        // `.env.production.example` would otherwise render stack traces, environment variables and
        // query dumps to whoever provoked an error. See ProductionDebugGuard.
        'force_debug_off_in_production' => env('CORE_FORCE_DEBUG_OFF_IN_PRODUCTION', true),

        // Passwords that appear in this repository's source, config or seeders.
        'published_passwords' => ['password'],

        // Used only to word the refusal message; the guard blocks on the password,
        // so keeping the published address with a real password still works.
        'published_emails' => ['admin@example.com'],
    ],
];

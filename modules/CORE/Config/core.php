<?php

use Modules\ACCOUNTS\Models\Journal;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\PurchaseRequisition;
use Modules\PURCHASE\Models\PurchaseRfq;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;

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

    /*
    |--------------------------------------------------------------------------
    | Document attachments (Phase 2 — F19)
    |--------------------------------------------------------------------------
    |
    | The allow-list of records that can carry attachments. Each type maps to its
    | model plus the permissions for reading (download) and managing (upload /
    | delete) files. Adding a type here is all it takes to enable attachments on
    | another record — the controller, routes and panel are generic.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Single sign-on (Phase 3)
    |--------------------------------------------------------------------------
    |
    | The whole feature is off until someone pastes a client id in. `enabled` gates the routes at request
    | time (not at boot), so turning it on is an env change plus `config:cache` — the login page asks the
    | service which providers are usable rather than reading the config itself, so a half-configured
    | provider cannot render a button that 500s.
    |
    | The flow is a plain OAuth2 authorization-code exchange that then reads the provider's `userinfo`
    | endpoint with the access token. That is deliberate: the alternative is fetching the OIDC discovery
    | document, downloading JWKS and verifying the id_token signature by hand, and a hand-rolled JWT
    | verifier is the kind of code that looks correct in review and fails open in production. `userinfo`
    | is authoritative because answering requires the token the provider just minted.
    |
    | `token_url` / `userinfo_url` are env-overridable so a private IdP (Keycloak, ADFS, an on-prem
    | Entra-compatible gateway) needs no code change — only the issuer's two endpoints and a client.
    |
    */
    'sso' => [
        'enabled' => filter_var(env('SSO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // Who may get an account minted on the spot. An existing local account is always allowed to sign
        // in by link — this switch is about *creating* people, not about admitting them.
        'auto_provision' => filter_var(env('SSO_AUTO_PROVISION', true), FILTER_VALIDATE_BOOLEAN),

        // Empty means "any verified address". A non-empty list is a hard gate on both paths: a directory
        // of identities is only as good as the domain you trust the issuer to have verified.
        'allowed_domains' => array_values(array_filter(array_map(
            static fn ($d): string => strtolower(trim((string) $d)),
            explode(',', (string) env('SSO_ALLOWED_DOMAINS', '')),
        ))),

        // State must come back inside this window. Long enough for a corporate SSO bounce and a password
        // prompt, short enough that a URL pasted into Slack next week cannot complete a login.
        'state_ttl' => (int) env('SSO_STATE_TTL', 600),

        'providers' => [
            'google' => [
                'label' => 'Google Workspace',
                'authorize_url' => env('SSO_GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
                'token_url' => env('SSO_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
                'userinfo_url' => env('SSO_GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
                'client_id' => (string) env('SSO_GOOGLE_CLIENT_ID', ''),
                'client_secret' => (string) env('SSO_GOOGLE_CLIENT_SECRET', ''),
                'scopes' => ['openid', 'email', 'profile'],
            ],
            'microsoft' => [
                'label' => 'Microsoft Entra ID',
                // The `{tenant}` placeholder is replaced with the configured tenant, so a single-tenant
                // app and a common-tenant app are both expressible.
                'authorize_url' => env('SSO_MICROSOFT_AUTHORIZE_URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize'),
                'token_url' => env('SSO_MICROSOFT_TOKEN_URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token'),
                'userinfo_url' => env('SSO_MICROSOFT_USERINFO_URL', 'https://graph.microsoft.com/oidc/userinfo'),
                'tenant' => (string) env('SSO_MICROSOFT_TENANT', 'common'),
                'client_id' => (string) env('SSO_MICROSOFT_CLIENT_ID', ''),
                'client_secret' => (string) env('SSO_MICROSOFT_CLIENT_SECRET', ''),
                'scopes' => ['openid', 'email', 'profile'],
            ],
        ],
    ],

    'attachments' => [
        'max_kb' => (int) env('CORE_ATTACHMENT_MAX_KB', 10240),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'],
        'types' => [
            'invoice' => ['model' => Invoice::class, 'view' => 'sales.invoices.view', 'manage' => 'sales.invoices.manage'],
            'bill' => ['model' => VendorBill::class, 'view' => 'purchase.bills.view', 'manage' => 'purchase.bills.manage'],
            'purchase-order' => ['model' => PurchaseOrder::class, 'view' => 'purchase.orders.view', 'manage' => 'purchase.orders.manage'],
            // F18: a requisition carries the quotation PDF it was estimated from, and an RFQ carries the
            // vendors' papers — which is the document an audit asks for next to an award decision.
            'purchase-requisition' => ['model' => PurchaseRequisition::class, 'view' => 'purchase.requisitions.view', 'manage' => 'purchase.requisitions.manage'],
            'purchase-rfq' => ['model' => PurchaseRfq::class, 'view' => 'purchase.rfq.view', 'manage' => 'purchase.rfq.manage'],
            'journal' => ['model' => Journal::class, 'view' => 'accounts.journals.view', 'manage' => 'accounts.journals.create'],
        ],
    ],
];

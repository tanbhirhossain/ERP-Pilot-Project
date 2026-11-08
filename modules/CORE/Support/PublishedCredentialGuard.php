<?php

namespace Modules\CORE\Support;

use Illuminate\Support\Facades\Log;

/**
 * Refuses the credentials this repository publishes, in production.
 *
 * The application ships a known-answer key to itself in four places at once:
 *
 *   - `config('core.super_admin')` defaults to `admin@example.com` / `password`;
 *   - `.env.example` sets `CORE_SUPER_ADMIN_PASSWORD=password`;
 *   - `SuperAdminSeeder` writes that pair, and grants it the `*` wildcard permission, which
 *     `enable_wildcard_permission` makes omnipotent;
 *   - `CoreSeeder` writes `Hash::make('password')` for three demo users holding the Manager,
 *     Auditor and Staff roles.
 *
 * In `local` that is a convenience and the test suite depends on it. In `APP_ENV=production` it is
 * the single largest remaining exposure in the project: anybody who has read the source — which is
 * public — can sign in as an administrator of any deployment that did not set the variable. There
 * was no runtime check; the only defence was a hardened `.env.production.example` that a deployment
 * had to remember to copy.
 *
 * Two things are guarded, because blocking only one leaves the hole open:
 *
 *   - **Writing.** `SuperAdminSeeder` refuses to run in production while the configured password is
 *     still a published one, and `CoreSeeder` gives its demo users an unknowable random password
 *     instead of the published one.
 *   - **Using.** `LoginRequest::authenticate()` refuses a login that presents a published password,
 *     after the credentials have been verified — so the message appears only for an account that
 *     really would have signed in, not for any typo that happens to be "password".
 *
 * Refusing the published string leaks nothing: the string is in the repository. That is precisely why
 * it cannot be a production credential. The generic `auth.failed` message is deliberately *not*
 * reused, because an operator locked out by this guard needs to know it was the guard and not a
 * mistyped password — otherwise the failure reads as a broken password reset.
 */
final class PublishedCredentialGuard
{
    private function __construct() {}

    public static function enabled(): bool
    {
        return (bool) config('core.security.block_published_credentials', true);
    }

    /**
     * Passwords this repository publishes in its source, config or seeders.
     *
     * @return list<string>
     */
    public static function publishedPasswords(): array
    {
        return array_values((array) config('core.security.published_passwords', ['password']));
    }

    /**
     * Emails this repository publishes, used only to word the message — the guard blocks on the
     * password, so an administrator who kept the published address but set a real one still gets in.
     */
    public static function isPublishedEmail(string $email): bool
    {
        $published = array_map('strtolower', (array) config('core.security.published_emails', ['admin@example.com']));
        $published[] = strtolower((string) config('core.super_admin.email', ''));

        return in_array(strtolower($email), array_filter($published), true);
    }

    /**
     * Deliberately does NOT treat `config('core.super_admin.password')` as published by itself:
     * that is the password the deployment chose, and comparing it against itself would refuse the
     * only credential a hardened production install has. What is published is the literal string
     * this repository ships, listed in `core.security.published_passwords`.
     */
    public static function isPublishedPassword(string $password): bool
    {
        return $password !== '' && in_array($password, self::publishedPasswords(), true);
    }

    /**
     * The environment the guard judges.
     *
     * Read from `config('app.env')` rather than `app()->environment()`. Both come from APP_ENV, but
     * the container binding is also what `Application::runningUnitTests()` inspects, so rebinding it
     * to exercise this guard would silently switch CSRF verification back on for the test that is
     * trying to post a login form — and every refusal test would fail for the wrong reason.
     */
    public static function environment(): string
    {
        return (string) (config('app.env') ?: app()->environment());
    }

    public static function inProduction(): bool
    {
        return self::environment() === 'production';
    }

    /**
     * Should a login presenting this pair be refused right now?
     *
     * Only in production, and only when the guard is enabled. A deployment that genuinely wants the
     * starter credentials can set `CORE_BLOCK_PUBLISHED_CREDENTIALS=false` — which is recorded in
     * the log the first time it is consulted, so the choice is at least visible.
     */
    public static function shouldRefuse(string $email, string $password): bool
    {
        if (! self::inProduction() || ! self::enabled()) {
            return false;
        }

        return self::isPublishedPassword($password);
    }

    public static function message(string $email): string
    {
        $who = self::isPublishedEmail($email)
            ? 'This is the administrator account the application ships with'
            : 'This password is published in the application source';

        return $who.'. It cannot be used to sign in while APP_ENV=production. '
            .'Set CORE_SUPER_ADMIN_PASSWORD before seeding, or reset the password from the console '
            .'with `php artisan tinker`, then sign in again.';
    }

    /**
     * Record the refusal. The password is never logged — only the fact that a published one was
     * presented, which is the actionable part.
     */
    public static function report(string $email): void
    {
        Log::critical('Login refused: a credential published in the application source was presented in production.', [
            'email' => $email,
            'environment' => self::environment(),
            'remedy' => 'Set CORE_SUPER_ADMIN_PASSWORD and re-seed, or reset the password from the console.',
        ]);
    }

    /**
     * May a seeder write one of the published passwords? False in production, so the hole cannot be
     * created by `migrate --seed` on a live deployment.
     */
    public static function seedersMayWritePublishedPassword(): bool
    {
        return ! self::inProduction();
    }
}

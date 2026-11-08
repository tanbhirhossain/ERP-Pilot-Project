<?php

namespace Modules\CORE\Support;

use Illuminate\Support\Facades\Log;

/**
 * Stops `APP_DEBUG=true` from taking effect on a production deployment.
 *
 * The repository ships two environment templates. `.env.example` is the local one and sets
 * `APP_DEBUG=true`, which is right for `APP_ENV=local` and is what Laravel's own template does.
 * `.env.production.example` is the hardened one and sets it false. Nothing forced an operator to
 * copy the second: a server handed the first runs in production with debug on, and debug on means
 * every uncaught exception renders its stack trace, its file contents, the request payload, the
 * environment variables — database credentials and `APP_KEY` among them — and the queries that ran,
 * to whoever managed to provoke the error. For an ERP holding payroll and ledger data that is a
 * disclosure of the whole system through a single bad request.
 *
 * **Why this forces the value off instead of refusing to boot.** A hard failure would guarantee the
 * operator notices, and would also turn a one-line misconfiguration into an outage of payroll,
 * invoicing and stock. The security-relevant behaviour is that debug output cannot be rendered, and
 * that is achieved immediately by correcting the value; the log line then names both the mistake and
 * the fix. `config('app.debug')` is the exact value `Illuminate\Foundation\Exceptions\Handler` reads
 * when deciding whether to render a stack trace, so correcting it in a service provider's `boot()` —
 * which runs before any request is handled — is not a cosmetic copy of the fix but the fix.
 *
 * The guard is deliberately narrow. It corrects one value it is certain about and does not try to
 * audit the rest of the environment; `.env.production.example` remains the place where the other
 * production settings are stated, and a deployment that ignores it will still be wrong in ways this
 * class does not cover.
 *
 * Switchable with `CORE_FORCE_DEBUG_OFF_IN_PRODUCTION=false`, the same convention
 * `PublishedCredentialGuard` uses, for a deployment that wants debug on in production and accepts
 * the exposure in writing.
 */
final class ProductionDebugGuard
{
    private function __construct() {}

    public static function enabled(): bool
    {
        return (bool) config('core.security.force_debug_off_in_production', true);
    }

    /**
     * Read from `config('app.env')` rather than `app()->environment()`, the same convention
     * `PublishedCredentialGuard::environment()` documents: both come from `APP_ENV`, but the config
     * key is what a test can change without rebinding the container's `env` key, which would also
     * flip `runningUnitTests()` and break every POST in the suite.
     */
    public static function environment(): string
    {
        return (string) (config('app.env') ?: app()->environment());
    }

    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    /**
     * Correct `app.debug` if a production deployment asked for debug, and say so in the log.
     *
     * @return bool true when the guard tripped, so a caller or a test can tell correction from silence
     */
    public static function enforce(): bool
    {
        if (! self::enabled() || ! self::isProduction() || ! config('app.debug')) {
            return false;
        }

        config(['app.debug' => false]);

        Log::critical('APP_DEBUG was enabled under APP_ENV=production; forced off by ProductionDebugGuard.', [
            'environment' => self::environment(),
            'effect' => 'app.debug set to false for this process, so exceptions render without stack traces',
            'fix' => 'set APP_DEBUG=false in .env. This deployment was probably given .env.example, which is the local template; the hardened one is .env.production.example.',
        ]);

        return true;
    }
}

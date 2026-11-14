<?php

namespace Modules\CORE\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Modules\CORE\Console\Commands\CreditLimitReviewCommand;
use Modules\CORE\Console\Commands\GenerateApiSpecCommand;
use Modules\CORE\Console\Commands\LeaveBalanceAccrualCommand;
use Modules\CORE\Console\Commands\LowStockAlertCommand;
use Modules\CORE\Console\Commands\OverdueInvoiceReminderCommand;
use Modules\CORE\Interfaces\ActivityRepositoryInterface;
use Modules\CORE\Interfaces\PermissionRepositoryInterface;
use Modules\CORE\Interfaces\RoleRepositoryInterface;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\CORE\Models\Activity;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\CORE\Repositories\ActivityRepository;
use Modules\CORE\Repositories\PermissionRepository;
use Modules\CORE\Repositories\RoleRepository;
use Modules\CORE\Repositories\UserRepository;
use Modules\CORE\Services\AuditTrailService;
use Modules\CORE\Services\ImportService;
use Modules\CORE\Support\ProductionDebugGuard;

class COREServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/core.php', 'core');

        // Spatie Permission — module-owned models + wildcard super-admin.
        config()->set('permission.models.role', Role::class);
        config()->set('permission.models.permission', Permission::class);
        config()->set('permission.enable_wildcard_permission', true);

        // Spatie Activity Log — module-owned model, decade-long retention so
        // audit records are never auto-purged (JCI record-keeping).
        config()->set('activitylog.activity_model', Activity::class);
        config()->set('activitylog.clean_after_days', 3655);
        // v4 of the package reads delete_records_older_than_days instead.
        config()->set('activitylog.delete_records_older_than_days', 3655);

        // Authentication — swap in the CORE user (HasRoles + LogsActivity)
        // so the app layer's User model stays untouched.
        config()->set('auth.providers.users.model', User::class);

        // SOLID: every consumer depends on an interface.
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->bind(ActivityRepositoryInterface::class, ActivityRepository::class);

        // Import orchestrator (Phase 1 — F5). A singleton so each module provider
        // can register its own importers into the same instance during boot.
        $this->app->singleton(ImportService::class, fn () => new ImportService([]));
    }

    /**
     * F13 — the shape of API traffic: a generous per-user read budget, a tight
     * budget on the one endpoint that mints credentials, and a revocation rule the
     * token table cannot express as a constraint.
     *
     * Rate limiting is keyed on the *user*, not the token, so five tokens for one
     * accountant are one caller, and an attacker cannot multiply their quota by
     * issuing tokens. Anonymous callers get the IP.
     *
     * `personal_access_tokens.tokenable_id` is a morph column, so no foreign key can
     * mean "revoke when the user goes away" — a deleted user would otherwise leave a
     * live bearer credential behind, still resolving to nothing and failing closed
     * only by accident. Revoking on delete makes the intent explicit.
     */
    protected function configureApiLimits(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('token-issue', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        User::deleting(function (User $user): void {
            $user->tokens()->delete();
        });
    }

    public function boot(): void
    {
        // Before anything else, and before any request is handled: a production deployment that was
        // given the development template must not be able to render stack traces. See
        // ProductionDebugGuard for why this corrects the value rather than refusing to boot.
        ProductionDebugGuard::enforce();

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'core');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->configureApiLimits();

        $this->shareInertiaProps();
        $this->registerAuditListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateApiSpecCommand::class,
                LowStockAlertCommand::class,
                OverdueInvoiceReminderCommand::class,
                CreditLimitReviewCommand::class,
                LeaveBalanceAccrualCommand::class,
            ]);
        }
    }

    /**
     * Shared Inertia props owned by this module: flash toasts + the ACL
     * context the premium sidebar / buttons use for permission-aware UI.
     */
    private function shareInertiaProps(): void
    {
        Inertia::share([
            'flash' => fn (): array => [
                'success' => session()->pull('success'),
                'error' => session()->pull('error'),
                'warning' => session()->pull('warning'),
                'info' => session()->pull('info'),
            ],
            'acl' => function (): array {
                $user = auth()->user();

                if (! $user || ! method_exists($user, 'getAllPermissions')) {
                    return [
                        'permissions' => [],
                        'roles' => [],
                        'is_super_admin' => false,
                    ];
                }

                $permissions = $user->getAllPermissions()->pluck('name')->values()->all();
                $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];

                return [
                    'permissions' => $permissions,
                    'roles' => $roles,
                    'is_super_admin' => in_array('*', $permissions, true),
                ];
            },
        ]);
    }

    /**
     * JCI-grade authentication audit: login / logout / failed / registration
     * are recorded with causer + request context without touching the app's
     * auth controllers.
     */
    private function registerAuditListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            app(AuditTrailService::class)->authEvent('login', $event->user, 'User logged in');
        });

        Event::listen(Logout::class, function (Logout $event): void {
            app(AuditTrailService::class)->authEvent('logout', $event->user, 'User logged out');
        });

        Event::listen(Registered::class, function (Registered $event): void {
            app(AuditTrailService::class)->authEvent('registered', $event->user, 'New user registered');
        });

        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditTrailService::class)->authEvent('login_failed', $event->user, 'Failed login attempt', [
                'email' => $event->credentials['email'] ?? null,
            ]);
        });
    }
}

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Two named limiters, for the routes that are expensive rather than merely writable — report
     * item 45, P1.6. A device sync walks the terminal's attendance log over the network and a bulk
     * roster write expands into one row per employee per day, so both are worth more than the
     * permission check that guards them: one authenticated user with `hrm.attendance.sync` could
     * hold a sync open in a loop and starve every other device. Neither limiter is keyed on the IP
     * address alone, because an office behind one NAT address would share a bucket and the twelfth
     * person to click Sync would be told to wait for the first.
     */
    public function boot(): void
    {
        RateLimiter::for('hrm-device-sync', function (Request $request) {
            return Limit::perMinute(6)->by($this->syncKey($request));
        });

        RateLimiter::for('hrm-bulk-write', function (Request $request) {
            return Limit::perMinute(12)->by('hrm-bulk|'.$this->actor($request));
        });
    }

    /** Per user, and per device where the route names one — one terminal's backlog is not another's. */
    private function syncKey(Request $request): string
    {
        return 'hrm-sync|'.$this->actor($request).'|'.($request->route('device') ?? 'all');
    }

    /**
     * The authenticated id, or the IP for a request that has none. Every route these limiters are
     * attached to is behind `auth`, so the fallback is for the case a route is later exposed and
     * the key silently degrades to a shared bucket — an anonymous caller is then limited per address
     * rather than not limited at all.
     */
    private function actor(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}

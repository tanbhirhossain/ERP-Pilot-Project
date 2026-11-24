<?php

namespace Modules\HRM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HRM\Support\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The login policy, enforced per request on `web` and `api`.
 *
 * Login-time gates stop new sessions, revocation kills outstanding tokens —
 * but a token minted before the last day arrived, or a web session open while
 * HR approves the resignation, would otherwise keep working. This middleware
 * closes that window: a leaver is logged out and refused everywhere, and an
 * account with the force flag reaches only the password plumbing until the
 * password is changed.
 *
 * Guests pass untouched, and so do users with no linked employee (admins,
 * integrators): the only query is one indexed employee lookup, and only for
 * accounts that hold the force flag or a link.
 */
class EnforceLoginPolicy
{
    public function __construct(private readonly \Modules\HRM\Services\EmployeeLoginService $logins) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->logins->isLeaverLocked($user)) {
            $this->logins->revokeAccess($user);

            if ($request->expectsJson()) {
                return $this->denied(
                    $request,
                    'This account has been deactivated. Please contact HR.',
                    'ACCOUNT_DEACTIVATED',
                    401
                );
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated. Please contact HR.']);
        }

        if ((bool) ($user->force_password_change ?? false) && ! $this->isPasswordPlumbing($request)) {
            if ($request->expectsJson()) {
                return $this->denied(
                    $request,
                    'You must change your password before continuing.',
                    'PASSWORD_CHANGE_REQUIRED',
                    403
                );
            }

            return redirect()->route('password.edit');
        }

        return $next($request);
    }

    /**
     * Routes a forced account may still reach: changing the password, and
     * leaving (a lock that traps you on the change screen with no logout is
     * a lock that gets bypassed).
     */
    private function isPasswordPlumbing(Request $request): bool
    {
        return in_array((string) $request->route()?->getName(), [
            'password.edit',
            'password.update',
            'logout',
            'api.v1.auth.password',
            'api.v1.auth.logout',
        ], true);
    }

    /**
     * The mobile surface speaks the envelope; the F13 integrator surface
     * speaks plain JSON. The controller namespace tells them apart.
     */
    private function denied(Request $request, string $message, string $code, int $status): Response
    {
        $controller = (string) ($request->route()?->getAction('controller') ?? '');

        if (str_starts_with($controller, 'Modules\\HRM\\Http\\Controllers\\Api\\V1')) {
            return ApiResponse::error($message, $status, ['code' => $code]);
        }

        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}

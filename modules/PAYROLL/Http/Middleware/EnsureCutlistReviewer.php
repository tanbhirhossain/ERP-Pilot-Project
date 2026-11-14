<?php

namespace Modules\PAYROLL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cutlist review is payroll's own sign-off desk: the sheet managers run it, and a dedicated reviewer
 * (payroll.cutlists.review) can reach every review surface except the HRM rebuild trigger — generate stays
 * behind payroll.sheets.manage, and queueing the salary sheet is separately gated on the approval.
 * Spatie's PermissionMiddleware takes a single permission, hence this any-of pair.
 */
final class EnsureCutlistReviewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('payroll.sheets.manage') || $user->can('payroll.cutlists.review')), 403);

        return $next($request);
    }
}

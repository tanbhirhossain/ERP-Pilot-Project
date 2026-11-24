<?php

namespace Modules\HRM\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Models\Employee\Employee;

/**
 * Self-service scoping for the mobile API, mirroring MyHrController's rule: the
 * employee code ALWAYS comes from the signed-in user (`employees.user_id`),
 * never from a route parameter or request field, so no tampered request can
 * read a colleague's payslip, leave or attendance.
 *
 * A login with no linked employee gets HTTP 409 + EMPLOYEE_NOT_LINKED (not a
 * bare 403), so the app can show the actionable "ask HR to link your account"
 * screen — the same failure the web self-service shows as a page.
 */
trait ResolvesApiEmployee
{
    protected function apiEmployee(Request $request): Employee|JsonResponse
    {
        $user = $request->user();

        $employee = $user !== null
            ? Employee::where('user_id', $user->id)->first()
            : null;

        if ($employee === null) {
            return ApiResponse::error(
                'No employee record is linked to this login. Please ask HR to link your account.',
                409,
                ['code' => 'EMPLOYEE_NOT_LINKED'],
            );
        }

        return $employee;
    }
}

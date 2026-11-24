<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Support\Api\ApiResponse;

/**
 * Mobile authentication (Sanctum personal access tokens).
 *
 * POST /api/v1/auth/login     {email, password, device_name?} → {token, user}
 * POST /api/v1/auth/logout    (revokes the calling token only)
 * GET  /api/v1/auth/profile   user + roles + linked employee + flags the app
 *                             needs to draw its menu (is_supervisor, unread count).
 *                             Named `profile`, not `me`: GET auth/me is the F13
 *                             integrator endpoint (CallerIdentity) on this same v1.
 */
class AuthController extends Controller
{
    #[ApiEndpoint(
        summary: 'Log in (mobile)',
        description: 'Email + password for a Sanctum token carrying the `mobile` ability. Wrong credentials answer 401; the token is rate-limited to 10 attempts a minute. Distinct from the F13 `POST auth/token` exchange, which mints ability-scoped integrator tokens.',
        request: 'MobileLogin',
        response: 'MobileEnvelope',
        status: '200',
        public: true,
    )]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            return ApiResponse::error('Invalid email or password.', 401, ['code' => 'INVALID_CREDENTIALS']);
        }

        $token = $user->createToken($data['device_name'] ?? 'mobile', ['mobile'])->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ], 'Logged in.');
    }

    #[ApiEndpoint(
        summary: 'Log out (revoke this token)',
        description: 'Deletes only the calling token, so other devices stay signed in. The app should also DELETE its device row so pushes stop.',
        response: 'MobileEnvelope',
        status: '200',
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::ok(null, 'Logged out.');
    }

    #[ApiEndpoint(
        summary: 'My profile, employee link and badge counts',
        description: 'User + roles + linked employee + `is_supervisor` + unread bell count — everything the app menu needs in one call. Named `profile` because GET auth/me is the F13 integrator endpoint on this same v1.',
        response: 'MobileEnvelope',
    )]
    public function profile(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->userPayload($request->user()), 'Profile.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $employee = Employee::where('user_id', $user->id)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'is_supervisor' => DB::table('employee_supervisors')->where('supervisor_id', $user->id)->exists(),
            'employee' => $employee !== null ? [
                'employee_id' => $employee->employee_id,
                'full_name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'joining_date' => $employee->joining_date?->toDateString(),
                'status' => $employee->status,
                'photo' => $employee->photo,
            ] : null,
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }
}

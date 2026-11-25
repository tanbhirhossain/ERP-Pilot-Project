<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;
use Modules\HRM\Interfaces\NotificationRepositoryInterface;
use Modules\HRM\Support\Api\ApiResponse;
use Illuminate\Validation\Rules\Password;
use Modules\HRM\Services\EmployeeLoginService;

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
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly EmployeeSupervisorRepositoryInterface $supervisors,
        private readonly NotificationRepositoryInterface $notifications,
    ) {}

    #[ApiEndpoint(
        summary: 'Log in (mobile)',
        description: 'Email or employee code + password for a Sanctum token carrying the `mobile` ability. Wrong credentials answer 401; the token is rate-limited to 10 attempts a minute. Distinct from the F13 `POST auth/token` exchange, which mints ability-scoped integrator tokens.',
        request: 'MobileLogin',
        response: 'MobileEnvelope',
        status: '200',
        public: true,
    )]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $logins = app(EmployeeLoginService::class);
        $loginEmail = $logins->resolveEmail($data['email']);
        $user = $loginEmail !== null ? $this->users->findByEmail($loginEmail) : null;

        if ($user === null || ! Hash::check($data['password'], (string) $user->password) || $logins->isLeaverLocked($user)) {
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

    #[ApiEndpoint(
        summary: 'Change my password',
        description: 'Current + new (confirmed) password. Clears the first-login force flag, so this is the screen the app must show when `must_change_password` is true or any endpoint answers PASSWORD_CHANGE_REQUIRED.',
        request: 'MobilePasswordChange',
        response: 'MobileEnvelope',
        status: '200',
    )]
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return ApiResponse::error('The current password is incorrect.', 422, [
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'force_password_change' => false,
        ])->save();

        return ApiResponse::ok(null, 'Password changed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload($user): array
    {
        $employee = $this->employees->findByUserId($user->id, ['department', 'designation']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'must_change_password' => (bool) ($user->force_password_change ?? false),
            'roles' => $user->getRoleNames()->values()->all(),
            'is_supervisor' => $this->supervisors->supervisesAnyone($user->id),
            'employee' => $employee !== null ? [
                'employee_id' => $employee->employee_id,
                'full_name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'joining_date' => $employee->joining_date?->toDateString(),
                'status' => $employee->status,
                'photo' => $employee->photo,
            ] : null,
            'unread_notifications' => $this->notifications->unreadCount($user),
        ];
    }
}

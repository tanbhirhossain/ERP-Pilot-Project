<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CORE\Support\Api\ApiEndpoint;
use Modules\HRM\Models\Device\UserDeviceToken;
use Modules\HRM\Support\Api\ApiResponse;

/**
 * Push device registry (Phase 4).
 *
 * POST   /api/v1/devices        register (idempotent upsert — call on every launch)
 * GET    /api/v1/devices        my installations
 * DELETE /api/v1/devices/{id}   unregister (call on logout so the next holder of the
 *                               phone stops receiving this user's pushes)
 *
 * Registering a token another user holds transfers it: that is exactly what a
 * re-login on a shared device must do. Tokens FCM reports dead are pruned
 * server-side by PushService.
 */
class DeviceApiController extends Controller
{
    #[ApiEndpoint(
        summary: 'My push installations',
        description: 'Every device token registered to the token user, newest first. An empty list means pushes for this user go nowhere — the in-app bell is unaffected.',
        response: 'MobileEnvelope',
    )]
    public function index(Request $request): JsonResponse
    {
        $tokens = UserDeviceToken::where('user_id', $request->user()->id)
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'platform', 'device_name', 'last_used_at', 'created_at']);

        return ApiResponse::ok($tokens, 'My devices.', ['total' => $tokens->count()]);
    }

    #[ApiEndpoint(
        summary: 'Register a push token (idempotent)',
        description: 'Upsert by token: the same call on every app launch refreshes ownership and last_used_at. Registering a token another user holds transfers it to the caller. Always answers 200 with a `created` flag rather than 201-once-then-200, so the app needs no status branching.',
        request: 'MobileDeviceRegister',
        response: 'MobileEnvelope',
        status: '200',
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:2048'],
            'platform' => ['nullable', Rule::in(UserDeviceToken::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $row = UserDeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? 'android',
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return ApiResponse::ok(
            ['id' => $row->id, 'created' => $row->wasRecentlyCreated, 'platform' => $row->platform],
            $row->wasRecentlyCreated ? 'Device registered.' : 'Device refreshed.'
        );
    }

    #[ApiEndpoint(
        summary: 'Unregister a push token',
        description: 'Deletes one installation row. Another user\'s row answers 404, never 403 — existence is not disclosed. The app should call this on logout.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'description' => 'The installation row id from GET devices.', 'schema' => ['type' => 'integer']]],
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = UserDeviceToken::where('id', $id)->where('user_id', $request->user()->id)->first();

        if ($row === null) {
            return ApiResponse::error('Device not found.', 404);
        }

        $row->delete();

        return ApiResponse::ok(null, 'Device unregistered.');
    }
}

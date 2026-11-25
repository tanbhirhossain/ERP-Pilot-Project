<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CORE\Support\Api\ApiEndpoint;
use Modules\HRM\Interfaces\Device\UserDeviceTokenRepositoryInterface;
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
    public function __construct(private readonly UserDeviceTokenRepositoryInterface $tokens) {}

    #[ApiEndpoint(
        summary: 'My push installations',
        description: 'Every device token registered to the token user, newest first. An empty list means pushes for this user go nowhere — the in-app bell is unaffected.',
        response: 'MobileEnvelope',
    )]
    public function index(Request $request): JsonResponse
    {
        $tokens = $this->tokens->listForUser($request->user()->id);

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
            'platform' => ['nullable', Rule::in($this->tokens->platforms())],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $row = $this->tokens->register(
            $data['token'],
            $request->user()->id,
            $data['platform'] ?? 'android',
            $data['device_name'] ?? null,
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
        $row = $this->tokens->findForUser($id, $request->user()->id);

        if ($row === null) {
            return ApiResponse::error('Device not found.', 404);
        }

        $this->tokens->delete($row);

        return ApiResponse::ok(null, 'Device unregistered.');
    }
}

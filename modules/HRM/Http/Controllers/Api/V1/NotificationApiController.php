<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Support\Api\ApiResponse;

/**
 * In-app notification bell (database channel only; FCM push is Phase 4).
 *
 * GET  /api/v1/notifications             my notifications, newest first
 * GET  /api/v1/notifications/unread-count  badge count
 * POST /api/v1/notifications/{id}/read   mark one read
 * POST /api/v1/notifications/read-all    mark all read
 *
 * Approval events start writing here in Phase 2; the endpoints ship now so
 * the app can draw the bell from day one.
 */
class NotificationApiController extends Controller
{
    #[ApiEndpoint(
        summary: 'My notifications (the bell)',
        description: 'Newest first. Each row carries ref_type + ref_id so the app deep-links the tap; push carries the same pair.',
        query: [
            ['name' => 'unread_only', 'in' => 'query', 'schema' => ['type' => 'boolean']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->notifications()->orderByDesc('created_at');

        if (! empty($data['unread_only'])) {
            $query->whereNull('read_at');
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'Notifications.');
    }

    #[ApiEndpoint(
        summary: 'Unread badge count',
        description: 'The single number the app badge polls.',
        response: 'MobileEnvelope',
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            ['unread' => $request->user()->unreadNotifications()->count()],
            'Unread count.'
        );
    }

    #[ApiEndpoint(
        summary: 'Mark one notification read',
        description: 'The id is the notification UUID. Another user\'s id answers 404.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
    )]
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification === null) {
            return ApiResponse::error('Notification not found.', 404);
        }

        $notification->markAsRead();

        return ApiResponse::ok(null, 'Marked as read.');
    }

    #[ApiEndpoint(
        summary: 'Mark all notifications read',
        description: 'Clears the badge in one call.',
        response: 'MobileEnvelope',
        status: '200',
    )]
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::ok(null, 'All marked as read.');
    }
}

<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell inbox (Phase 1 — F2 / U10).
 *
 * Reads are scoped to the signed-in user by the Notifiable relation — there is
 * no way to read another person's notifications. The JSON endpoints power the
 * header bell (poll); the Inertia page is the full inbox.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()->paginate(20)->withQueryString();

        return Inertia::render('CORE::Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** Header bell payload: latest 8 + unread count. */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()->limit(8)->get()->map(fn ($n) => [
            'id' => $n->id,
            'title' => $n->data['title'] ?? 'Notification',
            'body' => $n->data['body'] ?? '',
            'url' => $n->data['url'] ?? null,
            'level' => $n->data['level'] ?? 'info',
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->diffForHumans(),
        ]);

        return response()->json([
            'items' => $items,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}

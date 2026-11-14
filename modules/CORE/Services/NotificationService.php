<?php

namespace Modules\CORE\Services;

use Illuminate\Support\Facades\Notification;
use Modules\CORE\Models\User;
use Modules\CORE\Notifications\SystemNotification;

/**
 * The one way the application talks to a person who is not looking at the screen.
 *
 * Notifications are database-channel (the bell inbox). Every emitter hands over
 * a permission, not a user list: "tell whoever can approve vendor bills" survives
 * staff changes, because the audience is derived from the ACL at send time rather
 * than hard-coded ids.
 */
class NotificationService
{
    /**
     * Notify every active user holding the given permission.
     *
     * @param  array<string,mixed>  $data
     */
    public function notifyPermission(string $permission, string $title, string $body = '', ?string $url = null, string $level = 'info', array $data = []): int
    {
        $users = User::permission($permission)->get();

        if ($users->isEmpty()) {
            return 0;
        }

        Notification::send($users, new SystemNotification($title, $body, $url, $level, $data));

        return $users->count();
    }

    /**
     * Notify specific users by id.
     *
     * @param  array<int>  $userIds
     * @param  array<string,mixed>  $data
     */
    public function notifyUsers(array $userIds, string $title, string $body = '', ?string $url = null, string $level = 'info', array $data = []): int
    {
        $users = User::whereIn('id', array_unique($userIds))->get();

        if ($users->isEmpty()) {
            return 0;
        }

        Notification::send($users, new SystemNotification($title, $body, $url, $level, $data));

        return $users->count();
    }
}

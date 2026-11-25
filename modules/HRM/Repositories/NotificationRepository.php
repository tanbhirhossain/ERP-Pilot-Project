<?php

namespace Modules\HRM\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Interfaces\NotificationRepositoryInterface;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function paginateForUser(mixed $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        $query = $user->notifications()->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    public function unreadCount(mixed $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markRead(mixed $user, string $id): bool
    {
        $notification = $user->notifications()->find($id);

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    public function markAllRead(mixed $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }
}

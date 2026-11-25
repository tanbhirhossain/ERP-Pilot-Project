<?php

namespace Modules\HRM\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    /**
     * The $user is whatever the guard hands (CORE or App user): both carry
     * the Notifiable database channel, so the hint stays open on purpose.
     */
    public function paginateForUser(mixed $user, bool $unreadOnly, int $perPage): LengthAwarePaginator;

    public function unreadCount(mixed $user): int;

    /** Mark one notification read. False when the id is not the user's. */
    public function markRead(mixed $user, string $id): bool;

    public function markAllRead(mixed $user): void;
}

<?php

namespace Modules\HRM\Interfaces\Device;

use Illuminate\Support\Collection;
use Modules\HRM\Models\Device\UserDeviceToken;

interface UserDeviceTokenRepositoryInterface
{
    public function platforms(): array;

    /** @return Collection<int, UserDeviceToken> */
    public function listForUser(int $userId): Collection;

    /**
     * Idempotent register: the same token re-registered transfers ownership
     * to the caller (shared-device re-login) and refreshes last_used_at.
     */
    public function register(string $token, int $userId, string $platform, ?string $deviceName): UserDeviceToken;

    public function findForUser(int $id, int $userId): ?UserDeviceToken;

    public function delete(UserDeviceToken $row): void;
}

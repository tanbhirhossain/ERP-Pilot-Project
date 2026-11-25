<?php

namespace Modules\HRM\Repositories\Device;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Interfaces\Device\UserDeviceTokenRepositoryInterface;
use Modules\HRM\Models\Device\UserDeviceToken;

class UserDeviceTokenRepository implements UserDeviceTokenRepositoryInterface
{
    public function platforms(): array
    {
        return UserDeviceToken::PLATFORMS;
    }

    public function listForUser(int $userId): Collection
    {
        return UserDeviceToken::where('user_id', $userId)
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'platform', 'device_name', 'last_used_at', 'created_at']);
    }

    public function register(string $token, int $userId, string $platform, ?string $deviceName): UserDeviceToken
    {
        return DB::transaction(fn () => UserDeviceToken::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'platform' => $platform,
                'device_name' => $deviceName,
                'last_used_at' => now(),
            ],
        ));
    }

    public function findForUser(int $id, int $userId): ?UserDeviceToken
    {
        return UserDeviceToken::where('id', $id)->where('user_id', $userId)->first();
    }

    public function delete(UserDeviceToken $row): void
    {
        DB::transaction(fn () => $row->delete());
    }
}

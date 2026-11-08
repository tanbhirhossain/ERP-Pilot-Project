<?php

namespace Modules\HRM\Interfaces\Attendance;

use Illuminate\Support\Collection;
use Modules\HRM\Models\AttendanceSyncLog;
use Modules\HRM\Models\AttendanceSyncSetting;

interface AttendanceSyncRepositoryInterface
{
    public function getSettingForDevice(int $deviceId): ?AttendanceSyncSetting;

    public function upsertSetting(int $deviceId, array $data): AttendanceSyncSetting;

    public function logSync(int $deviceId, string $status, int $fetched, int $processed, ?string $msg = null, ?string $error = null): AttendanceSyncLog;

    public function recentLogs(int $limit = 20): Collection;
}

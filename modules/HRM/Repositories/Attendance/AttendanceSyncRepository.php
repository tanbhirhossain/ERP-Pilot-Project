<?php

namespace Modules\HRM\Repositories\Attendance;

use Illuminate\Support\Collection;
use Modules\HRM\Interfaces\Attendance\AttendanceSyncRepositoryInterface;
use Modules\HRM\Models\AttendanceSyncLog;
use Modules\HRM\Models\AttendanceSyncSetting;

class AttendanceSyncRepository implements AttendanceSyncRepositoryInterface
{
    public function getSettingForDevice(int $deviceId): ?AttendanceSyncSetting
    {
        return AttendanceSyncSetting::where('device_id', $deviceId)->first();
    }

    public function upsertSetting(int $deviceId, array $data): AttendanceSyncSetting
    {
        return AttendanceSyncSetting::updateOrCreate(['device_id' => $deviceId], $data);
    }

    public function logSync(int $deviceId, string $status, int $fetched, int $processed, ?string $msg = null, ?string $error = null): AttendanceSyncLog
    {
        return AttendanceSyncLog::create(['device_id' => $deviceId, 'status' => $status, 'records_fetched' => $fetched, 'records_processed' => $processed, 'message' => $msg, 'error' => $error, 'synced_at' => now()]);
    }

    public function recentLogs(int $limit = 20): Collection
    {
        return AttendanceSyncLog::with('device:id,name,ip_address')->orderBy('created_at', 'desc')->limit($limit)->get();
    }
}

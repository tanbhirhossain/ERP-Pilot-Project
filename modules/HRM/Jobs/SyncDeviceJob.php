<?php

namespace Modules\HRM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendanceSyncService;

class SyncDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public int $backoff = 5;

    public function __construct(
        public int $deviceId,
        public ?int $requestedBy = null,
        public ?string $fromDate = null,
        public ?string $toDate = null,
    ) {}

    public function handle(AttendanceSyncService $sync): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        // Also set max for worker process
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        $device = BiometricDevice::find($this->deviceId);
        if (!$device) {
            Log::warning('SyncDeviceJob: device not found', ['id' => $this->deviceId]);
            return;
        }
        if (!$device->is_enabled) {
            Log::info('SyncDeviceJob: device disabled, skipping', ['id' => $device->id]);
            return;
        }

        Log::info('SyncDeviceJob started', ['device' => $device->id, 'ip' => $device->ip_address, 'from' => $this->fromDate, 'to' => $this->toDate]);
        $result = $sync->syncDevice($device, $this->fromDate, $this->toDate);
        Log::info('SyncDeviceJob finished', ['device' => $device->id, 'result' => $result]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncDeviceJob failed', ['device' => $this->deviceId, 'error' => $e->getMessage()]);
    }
}

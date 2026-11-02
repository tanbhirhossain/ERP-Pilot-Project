<?php

namespace Modules\HRM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendancePipeline;

class SyncRawLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public int $deviceId,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function handle(AttendancePipeline $pipeline): void
    {
        @set_time_limit(0);
        $device = BiometricDevice::find($this->deviceId);
        if (!$device) {
            Log::warning('SyncRawLogsJob: device not found', ['id' => $this->deviceId]);
            return;
        }
        if (!$device->is_enabled) {
            Log::info('SyncRawLogsJob: device disabled', ['id' => $device->id]);
            return;
        }
        Log::info('[Job] Stage 1 SyncRawLogsJob started', ['device' => $device->id, 'ip' => $device->ip_address]);
        $result = $pipeline->stage1SyncRaw($device, $this->from, $this->to);
        Log::info('[Job] Stage 1 finished', ['device' => $device->id, 'result' => $result]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncRawLogsJob failed', ['device' => $this->deviceId, 'error' => $e->getMessage()]);
    }
}

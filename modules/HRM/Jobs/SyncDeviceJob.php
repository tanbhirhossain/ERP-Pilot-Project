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

    /**
     * Ceiling for this job alone: the service's own budget (`hrm.sync.budget_seconds`, 240s default) plus
     * room for the device to answer. Note that enforcing it needs ext-pcntl, which a Windows PHP usually
     * has not — there, `set_time_limit(0)` below plus the service's budget are what actually bound the run,
     * which is why the budget is the primary control and this number the safety net.
     */
    public int $timeout = 420;

    /**
     * The worker re-reserves a job after `queue.connections.database.retry_after` (400s in this app), and a job
     * that is still legitimately running at that point is handed to a second worker while the first is mid
     * flight. So the reserve window for this job has to outlast its own ceiling — the sync bounds itself at hrm.sync.budget_seconds (240s default) plus the device transfer.
     */
    public int $retryAfter = 480;

    public int $backoff = 5;

    public function __construct(
        public int $deviceId,
        public ?int $requestedBy = null,
        public ?string $fromDate = null,
        public ?string $toDate = null,
    ) {}

    public function handle(AttendanceSyncService $sync): void
    {
        // A queue worker is a long-lived process that owns its own lifetime, so nothing here may *shorten*
        // PHP's limit: doing that is what killed `queue:work` at 300s while the sync still had records to
        // read. The bounded work is `hrm.sync.budget_seconds` inside AttendanceSyncService, which stops the
        // sync politely and reports `partial` instead of dying.
        @set_time_limit(0);
        $device = BiometricDevice::find($this->deviceId);
        if (! $device) {
            Log::warning('SyncDeviceJob: device not found', ['id' => $this->deviceId]);

            return;
        }
        if (! $device->is_enabled) {
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

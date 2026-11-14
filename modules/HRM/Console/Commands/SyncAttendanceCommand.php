<?php

namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Jobs\SyncDeviceJob;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\Attendance\AttendanceSyncService;
use Modules\HRM\Support\Attendance\DailyDateArgument;

class SyncAttendanceCommand extends Command
{
    protected $signature = 'hrm:sync-attendance {--device= : Specific device ID} {--from= : From date Y-m-d or Y-m-d H:i:s} {--to= : To date} {--since= : Alias for --from (today, yesterday, 2024-01-01)} {--queue : Dispatch to queue instead of sync}';

    protected $description = 'Sync attendance from biometric devices (incremental by default, or date-filtered)';

    public function handle(AttendanceSyncService $sync, AttendanceProcessingService $proc): int
    {
        // This command owns the whole process (cron), so it is unlimited by choice; the per-device work is
        // bounded by hrm.sync.budget_seconds. It used to be set_time_limit(120), which simply killed a
        // large first sync in the middle of the device transfer.
        @set_time_limit(0);
        $deviceId = $this->option('device');
        $fromArgument = DailyDateArgument::resolve('from', $this->option('from') ?? $this->option('since'));
        $toArgument = DailyDateArgument::resolve('to', $this->option('to'));

        foreach (['from' => $fromArgument, 'to' => $toArgument] as $argument) {
            if (! $argument['ok']) {
                $this->error($argument['error']);

                return Command::FAILURE;
            }
        }

        $from = $fromArgument['value'];
        $to = $toArgument['value'];
        // `--since=today|yesterday` is handled inside DailyDateArgument::resolve().
        $useQueue = $this->option('queue');

        if ($deviceId) {
            $device = BiometricDevice::findOrFail($deviceId);
            if ($useQueue) {
                // Queue job doesn't support date filter yet — use sync directly for filtered
                SyncDeviceJob::dispatch($device->id, auth()->id() ?? null, $from, $to);
                $this->info("Dispatched SyncDeviceJob for {$device->name} ({$device->ip_address}) to queue. Run: php artisan queue:work");

                return 0;
            }
            $this->info("Syncing {$device->name} ({$device->ip_address}:{$device->port})".($from || $to ? " [{$from} → {$to}]" : ' [incremental since last sync]').' ...');
            $res = $sync->syncDevice($device, $from, $to);
            $this->info($res['message'] ?? 'Done');
            if (! ($res['ok'] ?? false)) {
                $this->error('Failed: '.$res['message']);
                $this->line('Tip: Device filters client-side per https://github.com/jmrashed/zkteco README 24.4 — still downloads full log. For true speed: 1) Sync Today only (--since=today), 2) use date range (--since=today) to limit processing — device keeps history.');

                return 1;
            }

            return 0;
        }

        // All devices
        if ($useQueue) {
            $devices = BiometricDevice::where('is_enabled', true)->get();
            foreach ($devices as $d) {
                SyncDeviceJob::dispatch($d->id, null, $from, $to);
            }
            $this->info('Dispatched '.count($devices).' jobs. Run: php artisan queue:work --queue=default --timeout=60');

            return 0;
        }

        $this->info('Syncing all devices'.($from || $to ? " [{$from} → {$to}]" : '').' ...');
        $results = $sync->syncAll($from, $to);
        foreach ($results as $id => $r) {
            $status = ($r['ok'] ?? false) ? '<info>OK</info>' : '<error>FAIL</error>';
            $this->info("Device {$id}: {$status} ".($r['message'] ?? 'ok')." ({$r['duration']}s)");
        }
        $proc->processPending(1000);
        $this->info('Processing pending logs done');

        return collect($results)->every(fn ($r) => $r['ok'] ?? false) ? 0 : 1;
    }
}

<?php

namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendancePipeline;

class RunAttendancePipelineCommand extends Command
{
    protected $signature = 'hrm:pipeline {--device= : Device ID (omit for all)} {--from= : From Y-m-d} {--to= : To Y-m-d} {--queue : Dispatch to queue chain} {--stage= : Run single stage: raw|pairs|daily}';
    protected $description = 'Run 3-stage attendance pipeline: Stage1 Sync Raw -> Stage2 Make Pairs -> Stage3 Prepare Attendance';

    public function handle(AttendancePipeline $pipeline): int
    {
        $deviceId = $this->option('device');
        $from = $this->option('from');
        $to = $this->option('to');
        $queue = $this->option('queue');
        $stage = $this->option('stage');

        if ($stage) {
            return $this->runSingleStage($pipeline, $stage, $deviceId, $from, $to);
        }

        if ($deviceId) {
            $device = BiometricDevice::findOrFail($deviceId);
            if ($queue) {
                $pipeline->dispatchForDevice($device->id, $from, $to);
                $this->info("Pipeline queued for {$device->name} ({$device->ip_address}) as 3 chained jobs. Run queue:work.");
                return 0;
            }
            $this->info("Running pipeline for {$device->name} ({$device->ip_address}) ...");
            $result = $pipeline->runForDevice($device, $from, $to);
            $this->printResult($result);
            return $result['ok'] ? 0 : 1;
        }

        // All devices
        if ($queue) {
            $pipeline->dispatchForAll($from, $to);
            $this->info("Pipeline queued for all enabled devices as chained jobs.");
            return 0;
        }

        $this->info("Running pipeline for all enabled devices ...");
        $results = $pipeline->runForAll($from, $to);
        foreach ($results as $id => $r) {
            $this->printResult($r);
        }
        return collect($results)->every(fn($r) => $r['ok']) ? 0 : 1;
    }

    private function runSingleStage(AttendancePipeline $pipeline, string $stage, ?string $deviceId, ?string $from, ?string $to): int
    {
        switch ($stage) {
            case 'raw':
                if (!$deviceId) { $this->error('--device required for raw stage'); return 1; }
                $device = BiometricDevice::findOrFail($deviceId);
                $res = $pipeline->stage1SyncRaw($device, $from, $to);
                $this->info(json_encode($res, JSON_PRETTY_PRINT));
                return $res['ok'] ? 0 : 1;
            case 'pairs':
                $res = $pipeline->stage2MakePairs(5000);
                $this->info(json_encode($res, JSON_PRETTY_PRINT));
                return $res['ok'] ? 0 : 1;
            case 'daily':
                $res = $pipeline->stage3PrepareAttendance($from, $to);
                $this->info(json_encode($res, JSON_PRETTY_PRINT));
                return $res['ok'] ? 0 : 1;
            default:
                $this->error("Invalid stage: $stage (use raw|pairs|daily)");
                return 1;
        }
    }

    private function printResult(array $r): void
    {
        $s1 = $r['stages']['stage1_raw'] ?? [];
        $s2 = $r['stages']['stage2_pairs'] ?? [];
        $s3 = $r['stages']['stage3_daily'] ?? [];
        $this->line("Device: {$r['device_name']} ({$r['device']}) - " . ($r['ok'] ? '<info>OK</info>' : '<error>PARTIAL</error>'));
        $this->line("  Stage 1 Raw  : " . json_encode(['ok' => $s1['ok'] ?? false, 'fetched' => $s1['fetched'] ?? 0, 'inserted' => $s1['inserted'] ?? 0]));
        $this->line("  Stage 2 Pairs: " . json_encode(['processed' => $s2['processed'] ?? 0, 'new' => $s2['new_pairs'] ?? 0, 'closed' => $s2['closed_pairs'] ?? 0, 'backfilled' => $s2['backfilled'] ?? 0, 'orphan_skip' => $s2['skipped_orphan'] ?? 0, 'errors' => $s2['errors'] ?? 0]));
        $this->line("  Stage 3 Daily: " . json_encode(['recalculated' => $s3['recalculated'] ?? 0]));
        $this->line("  Message: {$r['message']}");
        $orphan = $s2['skipped_orphan'] ?? 0;
        if ($orphan > 0 && ($s2['new_pairs'] ?? 0) === 0 && ($s2['closed_pairs'] ?? 0) === 0) {
            $this->warn("  Diagnosis: Stage2 processed $orphan logs as ORPHAN (device_user_id not in device_users). No pairs created!");
            $this->warn("     Fix: 1) php artisan hrm:reprocess-orphans --stats   2) Check HRM -> Device Users count");
            $this->warn("          3) If 0, run: php artisan db:seed --class=Modules\\HRM\\Database\\Seeders\\RealEmployeeBasicSeeder");
            $this->warn("             OR for each device: POST /hrm/devices/{id}/sync-users (HRM -> Device Users -> Sync from Device)");
            $this->warn("          4) Map orphan sample IDs to EMP-xxxx, then: php artisan hrm:reprocess-orphans --limit=5000");
        }
    }
}

<?php

namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendancePipeline;
use Modules\HRM\Support\Attendance\DailyDateArgument;

class RunAttendancePipelineCommand extends Command
{
    protected $signature = 'hrm:pipeline {--device= : Device ID (omit for all)} {--from= : From Y-m-d} {--to= : To Y-m-d} {--queue : Dispatch to queue chain} {--stage= : Run single stage: raw|pairs|daily}';

    protected $description = 'Run 3-stage attendance pipeline: Stage1 Sync Raw -> Stage2 Make Pairs -> Stage3 Prepare Attendance';

    public function handle(AttendancePipeline $pipeline): int
    {
        $deviceId = $this->option('device');
        $queue = $this->option('queue');
        $stage = $this->option('stage');

        // Check the dates before touching a device: a bad --from otherwise comes back as three identical
        // "Stage1 Raw FAIL" lines with no reason, because the parse error is caught inside the sync.
        $from = DailyDateArgument::resolve('from', $this->option('from'));
        $to = DailyDateArgument::resolve('to', $this->option('to'));

        foreach (['from' => $from, 'to' => $to] as $argument) {
            if (! $argument['ok']) {
                $this->error($argument['error']);

                return 1;
            }
        }

        $from = $from['value'];
        $to = $to['value'];

        // Before the first device: `ext-sockets` missing (default on Windows PHP) or no ZKTeCo package makes
        // every device fail identically in under a second, which reads exactly like "the device is full of
        // logs". Stages 2 and 3 do not touch a device, so they are not gated.
        if ($stage === null || $stage === 'raw') {
            if (($problem = $this->preflightMessage()) !== null) {
                if ($queue) {
                    $this->warn($problem);
                } else {
                    $this->error($problem);

                    return 1;
                }
            }
        }

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
            $this->info('Pipeline queued for all enabled devices as chained jobs.');

            return 0;
        }

        $this->info('Running pipeline for all enabled devices ...');
        $results = $pipeline->runForAll($from, $to);
        foreach ($results as $id => $r) {
            $this->printResult($r);
        }

        return collect($results)->every(fn ($r) => $r['ok']) ? 0 : 1;
    }

    /**
     * The two conditions under which no device read can possibly succeed, phrased as instructions.
     */
    private function preflightMessage(): ?string
    {
        foreach ((array) config('hrm.sync.required_extensions', ['sockets']) as $extension) {
            if (! extension_loaded($extension)) {
                // One line, kept short: the console wraps at terminal width, and a hint split across a wrap is
                // an operator reading `php --i` on one line and `ni` on the next.
                return "PHP extension \"{$extension}\" is not enabled — enable it in php.ini (php --ini).";
            }
        }

        if (! class_exists('Jmrashed\Zkteco\Lib\ZKTeco') && ! class_exists('TahaRafi\Zkteco\Lib\ZKTeco')) {
            return 'No ZKTeCo client is installed — a device sync needs one: composer require jmrashed/zkteco';
        }

        return null;
    }

    private function runSingleStage(AttendancePipeline $pipeline, string $stage, ?string $deviceId, ?string $from, ?string $to): int
    {
        switch ($stage) {
            case 'raw':
                if (! $deviceId) {
                    $this->error('--device required for raw stage');

                    return 1;
                }
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
        // `PARTIAL` means one thing here: the sync stopped because it reached `hrm.sync.budget_seconds`, with the
        // rest of the backlog left for the next pass. Anything else that is not OK is a failure — and labelling a
        // failure "PARTIAL" is what made a mistyped `--from` look like a device full of logs.
        $state = match (true) {
            (bool) ($r['ok'] ?? false) => '<info>OK</info>',
            (bool) ($s1['partial'] ?? false) => '<comment>PARTIAL</comment>',
            default => '<error>FAILED</error>',
        };
        $this->line("Device: {$r['device_name']} ({$r['device']}) - ".$state);
        // Only the keys the stage actually reported. `fetched: 0` from `$s1['fetched'] ?? 0` was a missing key
        // dressed up as a measurement: a stage that threw never counted anything, and "0 punches on the device"
        // is a different claim from "no answer".
        $summary = ['ok' => (bool) ($s1['ok'] ?? false)];

        foreach (['fetched', 'inserted', 'skipped_duplicate', 'unmapped_count'] as $key) {
            if (array_key_exists($key, $s1)) {
                $summary[$key] = $s1[$key];
            }
        }

        $this->line('  Stage 1 Raw  : '.json_encode($summary));
        $this->line('  Stage 2 Pairs: '.json_encode(['processed' => $s2['processed'] ?? 0, 'new' => $s2['new_pairs'] ?? 0, 'closed' => $s2['closed_pairs'] ?? 0, 'backfilled' => $s2['backfilled'] ?? 0, 'orphan_skip' => $s2['skipped_orphan'] ?? 0, 'errors' => $s2['errors'] ?? 0]));
        $this->line('  Stage 3 Daily: '.json_encode(['recalculated' => $s3['recalculated'] ?? 0]));
        // The stage's own reason, when the summary line above says only `ok:false`.
        foreach ([['Stage 1', $s1], ['Stage 2', $s2], ['Stage 3', $s3]] as [$label, $stage]) {
            if (is_array($stage) && array_key_exists('ok', $stage) && ! $stage['ok']) {
                $this->error("  {$label} reason: ".(string) ($stage['message'] ?? $stage['error'] ?? 'failed without a message'));
            }
        }

        $this->line("  Message: {$r['message']}");
        $orphan = $s2['skipped_orphan'] ?? 0;
        if ($orphan > 0 && ($s2['new_pairs'] ?? 0) === 0 && ($s2['closed_pairs'] ?? 0) === 0) {
            $this->warn("  Diagnosis: Stage2 processed $orphan logs as ORPHAN (device_user_id not in device_users). No pairs created!");
            $this->warn('     Fix: 1) php artisan hrm:reprocess-orphans --stats   2) Check HRM -> Device Users count');
            $this->warn('          3) If 0, run: php artisan db:seed --class=Modules\\HRM\\Database\\Seeders\\RealEmployeeBasicSeeder');
            $this->warn('             OR for each device: POST /hrm/devices/{id}/sync-users (HRM -> Device Users -> Sync from Device)');
            $this->warn('          4) Map orphan sample IDs to EMP-xxxx, then: php artisan hrm:reprocess-orphans --limit=5000');
        }
    }
}

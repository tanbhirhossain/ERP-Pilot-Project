<?php

namespace Modules\HRM\Services\Attendance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\BiometricDevice;

/**
 * 3-Stage Attendance Pipeline
 * Stage 1: Sync Raw Logs   -> attendance_logs (is_processed=false)
 * Stage 2: Make Pairs      -> attendance_pairs (punch_in/out, worked_hours)
 * Stage 3: Prepare Daily   -> attendance_dailies (primary + OT + grand_total + status)
 *
 * Each stage is idempotent and can be run independently.
 * Pipeline is queue-safe: each stage dispatched as separate job with chain.
 */
class AttendancePipeline
{
    public function __construct(
        private readonly AttendanceSyncService $syncService,
        private readonly AttendanceProcessingService $processor,
    ) {}

    /**
     * Run full pipeline for a device (sync + pair + daily)
     * Used by web controller and artisan command for synchronous single-device run.
     */
    public function runForDevice(BiometricDevice $device, ?string $from = null, ?string $to = null): array
    {
        // Professional unified default: last 7 days → now (not ALL) — user fact
        // If caller provided explicit range, use it; else compute defaults for all stages
        $effFrom = $from ?? now()->subDays(7)->startOfDay()->format('Y-m-d H:i:s');
        $effTo = $to ?? now()->format('Y-m-d H:i:s');
        // If caller gave only one side, respect it and derive other
        if ($from && !$to) $effTo = now()->format('Y-m-d H:i:s');
        if (!$from && $to) $effFrom = \Carbon\Carbon::parse($to)->subDays(7)->startOfDay()->format('Y-m-d H:i:s');
        // If explicit range was ALL (from=1970...), keep original
        if ($from) $effFrom = $from;
        if ($to) $effTo = $to;

        $stages = [];

        // Stage 1: Sync Raw Logs — pass effective range
        $stages['stage1_raw'] = $this->stage1SyncRaw($device, $effFrom, $effTo);
        if (!($stages['stage1_raw']['ok'] ?? false)) {
            // Don't proceed if sync failed (device unreachable)
            return $this->buildResult($device, $stages);
        }

        // Stage 2: Make Pairs — NO default filter so backlog outside 7d still clears; only filter if user explicitly gave from/to
        $stages['stage2_pairs'] = $this->stage2MakePairs(5000, $from, $to);

        // Stage 3: Prepare Attendance Dailies — let it default to last 7d inside if no explicit range
        $stages['stage3_daily'] = $this->stage3PrepareAttendance($from, $to);

        return $this->buildResult($device, $stages);
    }

    /**
     * Run full pipeline for all enabled devices
     */
    public function runForAll(?string $from = null, ?string $to = null): array
    {
        $devices = BiometricDevice::where('is_enabled', true)->get();
        $results = [];
        foreach ($devices as $device) {
            $results[$device->id] = $this->runForDevice($device, $from, $to);
            usleep(200000); // 200ms between devices
        }
        return $results;
    }

    /**
     * Stage 1: Sync Raw Logs from ZKTeCo device
     * Inserts into attendance_logs with is_processed=false.
     * Does NOT process pairs/dailies if $skipProcess=true (pipeline mode).
     */
    public function stage1SyncRaw(BiometricDevice $device, ?string $from = null, ?string $to = null, bool $skipProcess = true): array
    {
        Log::info('[Pipeline Stage 1] Sync Raw Logs', ['device' => $device->id, 'ip' => $device->ip_address, 'from' => $from, 'to' => $to]);

        // Temporarily disable auto-process inside syncService if pipeline wants separation
        // We achieve by calling syncDevice with a flag that skips internal processPending
        // For now, syncDevice still does one batch; pipeline will ensure full processing in stage 2
        $result = $this->syncService->syncDeviceRawOnly($device, $from, $to);

        if ($result['ok']) {
            Log::info('[Pipeline Stage 1] Completed', ['device' => $device->id, 'inserted' => $result['inserted'] ?? 0, 'fetched' => $result['fetched'] ?? 0]);
        } else {
            Log::error('[Pipeline Stage 1] Failed', ['device' => $device->id, 'error' => $result['message'] ?? 'unknown']);
        }

        return $result;
    }

    /**
     * Stage 2: Make Pairs from unprocessed logs — unified date filter
     * Pairs: punch_in -> punch_out within 24/72h window -> worked_hours
     * Date filter: only process logs where punch_time between from/to (if given)
     */
    public function stage2MakePairs(int $limit = 5000, ?string $from = null, ?string $to = null): array
    {
        Log::info('[Pipeline Stage 2] Make Pairs', ['limit' => $limit, 'from' => $from, 'to' => $to]);

        $total = ['processed' => 0, 'new_pairs' => 0, 'closed_pairs' => 0, 'errors' => 0, 'skipped_orphan' => 0, 'backfilled' => 0, 'batches' => 0];

        // First backfill orphans if any (retry) — use larger limit for big backlogs (151k case)
        // CRITICAL FIX: reprocessOrphans internally already calls processPending and creates pairs — must merge its counts!
        $orphanResult = $this->processor->reprocessOrphans(min($limit, 5000));
        // Merge reprocessOrphans results into total (previously discarded, causing 0-pair report even though DB had pairs)
        $total['backfilled'] = $orphanResult['backfilled'] ?? 0;
        $total['processed'] += $orphanResult['processed'] ?? 0;
        $total['new_pairs'] += $orphanResult['new_pairs'] ?? 0;
        $total['closed_pairs'] += $orphanResult['closed_pairs'] ?? 0;
        $total['errors'] += $orphanResult['errors'] ?? 0;
        $total['skipped_orphan'] += $orphanResult['skipped_orphan'] ?? 0;
        if (($orphanResult['backfilled'] ?? 0) > 0) {
            Log::info('[Pipeline Stage 2] Orphan backfill', ['backfilled' => $orphanResult['backfilled'], 'pairs_from_backfill' => ($orphanResult['new_pairs']??0)+($orphanResult['closed_pairs']??0)]);
        }
        // If we already processed a full batch via backfill, adjust remaining so we don't double-process same logs
        $remaining = $limit - ($orphanResult['processed'] ?? 0);

        // Process remaining in batches until backlog clear or limit hit — respect date filter if given
        // $remaining already adjusted for what reprocessOrphans handled
        $loops = 0;
        $maxLoops = 20; // up to 20k per pipeline call for large initial backlogs
        do {
            $batchLimit = min(1000, $remaining);
            $batch = $this->processor->processPending($batchLimit, $from, $to);
            $total['processed'] += $batch['processed'] ?? 0;
            $total['new_pairs'] += $batch['new_pairs'] ?? 0;
            $total['closed_pairs'] += $batch['closed_pairs'] ?? 0;
            $total['errors'] += $batch['errors'] ?? 0;
            $total['skipped_orphan'] += $batch['skipped_orphan'] ?? 0;
            $total['batches']++;
            $remaining -= $batch['processed'] ?? 0;
            $loops++;

            // Stop if less than full batch (no more backlog) or near timeout
            if (($batch['processed'] ?? 0) < 800) break;
            if ($loops >= $maxLoops) {
                Log::warning('[Pipeline Stage 2] Max loops reached', ['loops' => $loops, 'remaining' => $remaining, 'hint' => 'Backlog still exists, re-run pipeline to continue']);
                break;
            }
        } while ($remaining > 0);

        Log::info('[Pipeline Stage 2] Completed', $total);
        $total['ok'] = true;
        return $total;
    }

    /**
     * Stage 3: Prepare Daily Attendance Summaries
     * Recalculates dailies from pairs, ensures grand_total + status correct.
     * Also handles orphan recovery for dailies without primary.
     */
    public function stage3PrepareAttendance(?string $from = null, ?string $to = null): array
    {
        Log::info('[Pipeline Stage 3] Prepare Attendance', ['from' => $from, 'to' => $to]);

        if ($from && $to) {
            $affected = $this->processor->recalcForDateRange($from, $to);
            $classified = app(AttendanceDayReconciliationService::class)->reconcile(substr($from, 0, 10), substr($to, 0, 10));
            return ['ok' => true, 'recalculated' => $affected, 'missing_days' => $classified, 'from' => $from, 'to' => $to];
        }

        // If no date range, just ensure recent dailies are consistent (last 7 days)
        $from = $from ?? now()->subDays(7)->toDateString();
        $to = $to ?? now()->toDateString();
        $affected = $this->processor->recalcForDateRange($from, $to);
        $classified = app(AttendanceDayReconciliationService::class)->reconcile($from, $to);

        Log::info('[Pipeline Stage 3] Completed', ['recalculated' => $affected, 'missing_days' => $classified]);
        return ['ok' => true, 'recalculated' => $affected, 'missing_days' => $classified, 'from' => $from, 'to' => $to];
    }

    /**
     * Queue pipeline as chained jobs (non-blocking)
     */
    public function dispatchForDevice(int $deviceId, ?string $from = null, ?string $to = null, ?int $requestedBy = null): void
    {
        \Modules\HRM\Jobs\SyncRawLogsJob::dispatch($deviceId, $from, $to)->chain([
            new \Modules\HRM\Jobs\MakePairsJob(5000, $from, $to),
            new \Modules\HRM\Jobs\PrepareAttendanceJob($from, $to),
        ]);
        Log::info('[Pipeline] Dispatched chained jobs', ['device' => $deviceId]);
    }

    public function dispatchForAll(?string $from = null, ?string $to = null): void
    {
        $devices = BiometricDevice::where('is_enabled', true)->get();
        foreach ($devices as $device) {
            $this->dispatchForDevice($device->id, $from, $to);
        }
    }

    private function buildResult(BiometricDevice $device, array $stages): array
    {
        $pairsOk = (($stages['stage2_pairs']['new_pairs'] ?? 0) + ($stages['stage2_pairs']['closed_pairs'] ?? 0) > 0) || (($stages['stage2_pairs']['skipped_orphan'] ?? 0) === 0);
        $ok = ($stages['stage1_raw']['ok'] ?? false) && $pairsOk && ($stages['stage3_daily']['ok'] ?? true);
        $orphanNote = '';
        if (($stages['stage2_pairs']['skipped_orphan'] ?? 0) > 0) {
            $orphanNote = sprintf(' [ORPHAN %d needs DeviceUser mapping!]', $stages['stage2_pairs']['skipped_orphan']);
        }
        $msg = sprintf(
            'Pipeline %s for %s (%s): Stage1 Raw %s, Stage2 Pairs %d new/%d closed%s, Stage3 Daily %d recalc',
            $ok ? 'OK' : 'PARTIAL',
            $device->name,
            $device->ip_address,
            $stages['stage1_raw']['ok'] ? 'OK (' . ($stages['stage1_raw']['inserted'] ?? 0) . ' new)' : 'FAIL',
            $stages['stage2_pairs']['new_pairs'] ?? 0,
            $stages['stage2_pairs']['closed_pairs'] ?? 0,
            $orphanNote,
            $stages['stage3_daily']['recalculated'] ?? 0
        );

        return [
            'ok' => $ok,
            'device' => $device->id,
            'device_name' => $device->name,
            'stages' => $stages,
            'message' => $msg,
        ];
    }
}

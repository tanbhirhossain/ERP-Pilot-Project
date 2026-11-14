<?php

namespace Modules\HRM\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jmrashed\Zkteco\Lib\ZKTeco;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceSyncRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;

class AttendanceSyncService
{
    /** Punches per statement when checking what the device already has, and when inserting what it has not. */
    private const INGEST_CHUNK = 500;

    /** Device user ids per statement when resolving the employee mappings. */
    private const MAPPING_CHUNK = 1000;

    public function __construct(
        private readonly AttendanceLogRepositoryInterface $logs,
        private readonly AttendanceSyncRepositoryInterface $syncRepo,
        private readonly AttendanceProcessingService $processor,
    ) {}

    /** Sync all enabled devices - respects rate limits and timeouts */
    public function syncAll(?string $fromDate = null, ?string $toDate = null): array
    {
        $devices = BiometricDevice::where('is_enabled', true)->where('auto_sync_enabled', true)->get();
        if ($devices->isEmpty()) {
            // Also try to sync any is_enabled devices if no auto_sync configured (fallback)
            $devices = BiometricDevice::where('is_enabled', true)->where('status', 'Active')->get();
        }
        $results = [];
        foreach ($devices as $device) {
            $results[$device->id] = $this->syncDevice($device, $fromDate, $toDate);
            // Small delay between devices to avoid overwhelming network
            if (count($results) > 1) {
                usleep(200000);
            } // 200ms
        }

        return $results;
    }

    /**
     * Sync single device via ZKTeCo - handles real package + simulation mode
     * Supports date-range filtering to reduce DB work (note: package still downloads full log, see docs)
     */
    public function syncDevice(BiometricDevice $device, ?string $fromDate = null, ?string $toDate = null, ?int $budgetSeconds = null): array
    {
        $start = microtime(true);
        $deadline = $start + max(1, $budgetSeconds ?? (int) config('hrm.sync.budget_seconds', 240));
        // PHP's own time limit is the *caller's* business, not this method's. A queue job runs
        // `set_time_limit(0)` because the worker owns the process lifetime; an HTTP request keeps the php.ini
        // cap and should keep it. This method used to re-arm `set_time_limit(300)` here, which un-did the
        // job's `0` — and on Windows, where the limit counts wall-clock time, that killed the worker mid-sync
        // with "Maximum execution time of 300 seconds exceeded". The method then *also* budgeted itself 250s
        // of processing plus however long the device took to answer, i.e. it was written to overrun its own
        // limit. `$deadline` replaces both numbers, and unlike a fatal it stops between records so the caller
        // can decide what to do with the remainder.
        $prevSocketTimeout = @ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', '3');

        try {
            // Determine incremental date window if caller didn't specify
            // Use last_sync_at - sync_window_hours to catch late/missed punches, avoid re-pulling months of history
            if (! $fromDate && ! $toDate) {
                $setting = $this->syncRepo->getSettingForDevice($device->id);
                $windowHours = $setting?->sync_window_hours ?? $device->sync_window_hours ?? 24;
                // First sync (never synced) -> fetch ALL logs (null = no filter) so even unmapped users sync
                // User explicitly wants ALL device logs even if not mapped, to see in attendance_logs
                // Incremental only applies after first successful sync sets last_sync_at
                if ($device->last_sync_at) {
                    $fromDate = Carbon::parse($device->last_sync_at)->subHours($windowHours)->format('Y-m-d H:i:s');
                    $toDate = now()->format('Y-m-d H:i:s');
                } else {
                    // First sync defaults to last 7 days (not ALL)
                    // Default last 7 days to now
                    $fromDate = now()->subDays(7)->startOfDay()->format('Y-m-d H:i:s');
                    $toDate = now()->format('Y-m-d H:i:s');
                    Log::info('First sync defaults to last 7 days', ['device' => $device->id, 'note' => 'Use explicit --from=1970-01-01 for historical ALL']);
                }
            }

            $fetched = $this->fetchFromDevice($device, $fromDate, $toDate);
            $inserted = 0;
            $skippedDuplicate = 0;
            $filteredOut = 0;
            $unmappedCount = 0;

            // Track incremental filtering stats (fetchFromDevice already filtered, but double-check for safety)
            if (! empty($fetched) && ($fromDate || $toDate)) {
                $from = $fromDate ? Carbon::parse($fromDate) : null;
                $to = $toDate ? Carbon::parse($toDate) : null;
                // Service already filtered, but we log for transparency
                $filteredCount = count($fetched);
                Log::info('ZK incremental sync window', ['device' => $device->id, 'from' => $fromDate, 'to' => $toDate, 'fetched_filtered' => $filteredCount]);
            }

            $ingest = $this->ingestDeviceLogs($device, $fetched, $deadline);
            $inserted += $ingest['inserted'];
            $skippedDuplicate = $ingest['skipped_duplicate'];
            $unmappedCount = $ingest['unmapped'];
            $partial = $ingest['partial'];

            // Process pending logs in batches until backlog clear or out of budget ( handles 10k+ logs )
            // CRITICAL: Do NOT filter by date here — must clear backlog (474 mapped outside 7d would be missed if filtered)
            $processResult = ['processed' => 0, 'new_pairs' => 0, 'closed_pairs' => 0, 'errors' => 0, 'skipped_orphan' => 0];
            $loops = 0;
            $maxLoops = 10; // up to 10k logs per sync
            do {
                $batch = $this->processor->processPending(1000);
                $processResult['processed'] += $batch['processed'] ?? 0;
                $processResult['new_pairs'] += $batch['new_pairs'] ?? 0;
                $processResult['closed_pairs'] += $batch['closed_pairs'] ?? 0;
                $processResult['errors'] += $batch['errors'] ?? 0;
                $processResult['skipped_orphan'] = ($processResult['skipped_orphan'] ?? 0) + ($batch['skipped_orphan'] ?? 0);
                $loops++;
                // Stop if less than full batch (no more) or out of budget
                if (($batch['processed'] ?? 0) < 800) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    $partial = true;
                    Log::warning('Sync processing loop stopped at its time budget', ['device' => $device->id, 'loops' => $loops]);

                    break;
                }
            } while ($loops < $maxLoops);

            // Stage 3: Prepare Daily — unified Sync=Pipeline (daily recalc for effective range)
            $recalculated = 0;
            try {
                $recalcFrom = $fromDate ? Carbon::parse($fromDate)->toDateString() : now()->subDays(7)->toDateString();
                $recalcTo = $toDate ? Carbon::parse($toDate)->toDateString() : now()->toDateString();
                $recalculated = $this->processor->recalcForDateRange($recalcFrom, $recalcTo);
            } catch (\Throwable $e) {
                Log::warning('Daily recalc failed in syncDevice', ['error' => $e->getMessage()]);
            }

            $duration = round(microtime(true) - $start, 2);

            // A partial sync must not move the incremental window past the punches it never read.
            if (! $partial) {
                $device->update(['last_sync_at' => now()]);
            }
            $setting = $this->syncRepo->getSettingForDevice($device->id);
            if ($partial && $setting) {
                // Come back for the rest shortly instead of after a full interval.
                $setting->update(['next_sync_at' => now()->addMinute()]);
            } elseif ($setting) {
                $setting->update(['last_sync_at' => now(), 'next_sync_at' => now()->addMinutes($setting->sync_interval_minutes ?? $device->sync_interval_minutes ?? 15)]);
            } else {
                // Auto-create setting on first sync
                $this->syncRepo->upsertSetting($device->id, [
                    'auto_sync_enabled' => $device->auto_sync_enabled ?? true,
                    'sync_interval_minutes' => $device->sync_interval_minutes ?? 15,
                    'sync_window_hours' => 24,
                    'last_sync_at' => now(),
                    'next_sync_at' => now()->addMinutes(15),
                    'is_active' => true,
                ]);
            }

            $processed = $processResult['processed'] ?? 0;
            $newPairs = $processResult['new_pairs'] ?? 0;
            $closedPairs = $processResult['closed_pairs'] ?? 0;

            // Help debug: if fetched but 0 inserted, warn why (all dups? filtered? demo?)
            if (count($fetched) > 0 && $inserted === 0 && $skippedDuplicate === 0) {
                Log::warning('Fetched but 0 inserted - check filtering/deduplication', ['device' => $device->id, 'fetched' => count($fetched), 'from' => $fromDate, 'to' => $toDate, 'sample' => array_slice($fetched, 0, 2)]);
            }
            if (count($fetched) === 0) {
                Log::warning('Fetched 0 records - device may have no logs in window or all filtered', ['device' => $device->id, 'from' => $fromDate, 'to' => $toDate, 'last_sync_at' => $device->last_sync_at]);
            }

            $rangeInfo = ($fromDate || $toDate) ? " [{$fromDate} → {$toDate}]" : ' [ALL]';
            $unmappedInfo = ($unmappedCount ?? 0) > 0 ? ", {$unmappedCount} unmapped (no DeviceUser)" : '';
            $msg = 'Fetched '.count($fetched)." raw{$rangeInfo}, inserted {$inserted} new ({$skippedDuplicate} dups skipped{$unmappedInfo}), processed {$processed} logs ({$newPairs} new pairs, {$closedPairs} closed), daily recalc {$recalculated} in {$duration}s".($partial ? ' - PARTIAL: time budget reached, the remainder is left for the next run (last_sync_at not advanced)' : '');
            $this->syncRepo->logSync($device->id, $partial ? 'Partial' : 'Success', count($fetched), $inserted + $processed, $msg, null);

            return ['ok' => true, 'fetched' => count($fetched), 'inserted' => $inserted, 'processed' => $processed, 'skipped_duplicate' => $skippedDuplicate, 'recalculated' => $recalculated, 'partial' => $partial, 'message' => $msg, 'duration' => $duration, 'from' => $fromDate, 'to' => $toDate];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $start, 2);
            Log::error('ZK Sync failed', ['device' => $device->id, 'ip' => $device->ip_address, 'error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 3000)]);
            $this->syncRepo->logSync($device->id, 'Failed', 0, 0, null, $e->getMessage()." ({$duration}s)");

            return ['ok' => false, 'message' => $e->getMessage(), 'duration' => $duration];
        } finally {
            if ($prevSocketTimeout !== false) {
                @ini_set('default_socket_timeout', $prevSocketTimeout);
            }
        }
    }

    /**
     * Stage 1 only: Sync Raw Logs without pairing (for pipeline)
     * Returns inserted count, logs are left is_processed=false for Stage 2
     */
    public function syncDeviceRawOnly(BiometricDevice $device, ?string $fromDate = null, ?string $toDate = null, ?int $budgetSeconds = null): array
    {
        $start = microtime(true);
        // See syncDevice(): the caller owns PHP's time limit, this method owns a deadline.
        $deadline = $start + max(0, $budgetSeconds ?? (int) config('hrm.sync.budget_seconds', 240));
        $prevSocketTimeout = @ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', '3');
        try {
            if (! $fromDate && ! $toDate) {
                $setting = $this->syncRepo->getSettingForDevice($device->id);
                $windowHours = $setting?->sync_window_hours ?? $device->sync_window_hours ?? 24;
                if ($device->last_sync_at) {
                    $fromDate = Carbon::parse($device->last_sync_at)->subHours($windowHours)->format('Y-m-d H:i:s');
                    $toDate = now()->format('Y-m-d H:i:s');
                } else {
                    $fromDate = now()->subDays(7)->startOfDay()->format('Y-m-d H:i:s');
                    $toDate = now()->format('Y-m-d H:i:s');
                }
            }
            $fetched = $this->fetchFromDevice($device, $fromDate, $toDate);
            $inserted = 0;
            $skippedDuplicate = 0;
            $unmappedCount = 0;
            $ingest = $this->ingestDeviceLogs($device, $fetched, $deadline);
            $inserted += $ingest['inserted'];
            $skippedDuplicate = $ingest['skipped_duplicate'];
            $unmappedCount = $ingest['unmapped'];
            $partial = $ingest['partial'];

            $duration = round(microtime(true) - $start, 2);

            if (! $partial) {
                $device->update(['last_sync_at' => now()]);
            }

            $setting = $this->syncRepo->getSettingForDevice($device->id);
            if ($partial && $setting) {
                $setting->update(['next_sync_at' => now()->addMinute()]);
            } elseif ($setting) {
                $setting->update(['last_sync_at' => now(), 'next_sync_at' => now()->addMinutes($setting->sync_interval_minutes ?? $device->sync_interval_minutes ?? 15)]);
            } else {
                $this->syncRepo->upsertSetting($device->id, ['auto_sync_enabled' => $device->auto_sync_enabled ?? true, 'sync_interval_minutes' => $device->sync_interval_minutes ?? 15, 'sync_window_hours' => 24, 'last_sync_at' => now(), 'next_sync_at' => now()->addMinutes(15), 'is_active' => true]);
            }
            $rangeInfo = ($fromDate || $toDate) ? " [{$fromDate} → {$toDate}]" : ' [ALL]';
            $msg = 'Stage1 Raw: Fetched '.count($fetched)."{$rangeInfo}, inserted {$inserted} ({$skippedDuplicate} dups, {$unmappedCount} unmapped) in {$duration}s".($partial ? ' - PARTIAL: time budget reached, rest left for the next run' : '');
            $this->syncRepo->logSync($device->id, 'Success', count($fetched), $inserted, $msg, null);

            return ['ok' => true, 'fetched' => count($fetched), 'inserted' => $inserted, 'skipped_duplicate' => $skippedDuplicate, 'unmapped' => $unmappedCount, 'partial' => $partial, 'message' => $msg, 'duration' => $duration, 'from' => $fromDate, 'to' => $toDate];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $start, 2);
            Log::error('ZK Sync Raw failed', ['device' => $device->id, 'error' => $e->getMessage()]);
            $this->syncRepo->logSync($device->id, 'Failed', 0, 0, null, $e->getMessage()." ({$duration}s)");

            return ['ok' => false, 'message' => $e->getMessage(), 'duration' => $duration];
        } finally {
            if ($prevSocketTimeout !== false) {
                @ini_set('default_socket_timeout', $prevSocketTimeout);
            }
        }
    }

    /**
     * Fetch from device - handles real package, with robust error handling
     * Supports: getAttendance(), getAttendanceWithUser(), getAttendanceByDateRange(), getSanitizedAttendance()
     *
     * @see https://github.com/jmrashed/zkteco README 24.4 — date range filters CLIENT-SIDE, device still transfers full log
     */
    private function fetchFromDevice(BiometricDevice $device, ?string $fromDate = null, ?string $toDate = null): array
    {
        // Use jmrashed/zkteco if installed - wrap to allow demo fallback on network errors
        $zkClass = null;
        foreach (['\Jmrashed\Zkteco\Lib\ZKTeco', '\TahaRafi\Zkteco\Lib\ZKTeco'] as $c) {
            if (class_exists($c)) {
                $zkClass = $c;
                break;
            }
        } if ($zkClass) {
            try {
                return $this->fetchViaJmrashed($device, $fromDate, $toDate, $zkClass);
            } catch (\Throwable $e) {
                if ($this->shouldUseDemoFallback($device, $e)) {
                    Log::warning('ZK device unreachable — using DEMO simulation data', ['device' => $device->id, 'ip' => $device->ip_address, 'error' => $e->getMessage()]);

                    return $this->generateDemoAttendance($device, $fromDate, $toDate);
                }
                throw $e;
            }
        }

        // Fallback: no package - log and provide demo data for local/testing
        Log::warning('ZKTeCo package not installed - sync in manual-only mode', ['device' => $device->id]);
        if ($this->simulationAllowed()) {
            Log::info('Using DEMO simulation data (package missing)', ['device' => $device->id]);

            return $this->generateDemoAttendance($device, $fromDate, $toDate);
        }
        throw new \Exception('ZKTeCo package not installed. Run: composer require jmrashed/zkteco && php artisan migrate. For demo without device, set APP_ENV=local to enable simulation.');
    }

    private function fetchViaJmrashed(BiometricDevice $device, ?string $fromDate = null, ?string $toDate = null, ?string $zkClass = '\\Jmrashed\\Zkteco\\Lib\\ZKTeco'): array
    {
        // Validate sockets extension
        if (! extension_loaded('sockets')) {
            throw new \Exception('PHP sockets extension not enabled - required for ZKTeCo UDP communication');
        }

        // Fast pre-check: is device reachable at TCP level? Fails in ~2s instead of 60s vendor hang.
        if (! $this->isReachable($device, 3)) {
            throw new \Exception("Device {$device->name} ({$device->ip_address}:{$device->port}) unreachable - check IP/firewall, use 'Test Connection' first. If Test passes but sync hangs, device may need UDP mode or comm key mismatch.");
        }

        $ip = $device->ip_address;
        $port = $device->port ?? 4370;

        // Set socket timeouts before ZK library creates its socket
        // The vendor library blocks up to 60.5s per socket read, so the *socket* gets the longer timeout.
        // PHP's execution limit is deliberately not raised here: the caller (job, console command or HTTP
        // request) owns it, and the calling sync methods bound themselves with `hrm.sync.budget_seconds`.
        @ini_set('default_socket_timeout', '60');

        $zk = null;
        if ($zkClass && class_exists($zkClass)) {
            try {
                $ref = new \ReflectionClass($zkClass);
                $ctor = $ref->getConstructor();
                $params = $ctor ? $ctor->getParameters() : [];
                if (count($params) >= 3) {
                    $protocol = $device->connection_type ?? 'TCP';
                    $zk = $ref->newInstance($ip, (int) $port, $protocol, 30);
                    Log::info('ZK instantiated with protocol', ['device' => $device->id, 'class' => $zkClass, 'protocol' => $protocol]);
                } else {
                    $zk = new $zkClass($ip, $port);
                }
            } catch (\Throwable $e) {
                Log::warning('ZK ctor protocol failed, fallback 2-arg', ['error' => $e->getMessage()]);
                $zk = new $zkClass($ip, $port);
            }
        } else {
            $zk = new ZKTeco($ip, $port);
        }

        // Connect with timeout handling — ZK library's connect() can hang (Util.php:376) on bad firmware
        $connected = false;
        try {
            // Wrap in time-box: if connect takes >5s, abort
            $connectStart = microtime(true);
            $connected = $zk->connect();
            $connectDuration = microtime(true) - $connectStart;
            if ($connectDuration > 5) {
                Log::warning('ZK connect slow', ['device' => $device->id, 'duration' => $connectDuration]);
            }
        } catch (\Throwable $e) {
            try {
                if (method_exists($zk, 'enableDevice')) {
                    $zk->enableDevice();
                }
            } catch (\Throwable $_) {
            }
            try {
                $zk->disconnect();
            } catch (\Throwable $_) {
            }
            throw new \Exception("Connection exception to {$ip}:{$port} - ".$e->getMessage());
        }

        if (! $connected) {
            throw new \Exception("Unable to connect to device {$ip}:{$port} - device unreachable, busy (another sync in progress), or comm key mismatch. Try 'Test Connection' first.");
        }

        try {
            // Disable device to prevent new punches during bulk transfer (faster, more reliable)
            // Vendor: $zk->disableDevice() -> CMD_DISABLE_DEVICE, then enable after
            try {
                if (method_exists($zk, 'disableDevice')) {
                    $zk->disableDevice();
                    Log::info('Device disabled for sync', ['device' => $device->id]);
                }
            } catch (\Throwable $_) {
            }

            // Prefer package's date-range method if caller requested window — note: docs say this STILL downloads full log
            // and filters client-side (https://github.com/jmrashed/zkteco README 24.4), so it helps DB work but not transfer time.
            // True speed fix is incremental sync (last_sync_at - window) — device keeps history, server filters incrementally.
            $data = null;
            if (($fromDate || $toDate) && method_exists($zk, 'getAttendanceByDateRange')) {
                $from = $fromDate ?? '1970-01-01';
                $to = $toDate ?? now()->format('Y-m-d H:i:s');
                try {
                    $data = $zk->getAttendanceByDateRange($from, $to);
                    Log::info('ZK getAttendanceByDateRange used', ['device' => $device->id, 'from' => $from, 'to' => $to, 'count' => is_array($data) ? count($data) : 'non-array']);
                } catch (\Throwable $e) {
                    Log::warning('getAttendanceByDateRange failed, falling back to getAttendance', ['device' => $device->id, 'error' => $e->getMessage()]);
                    $data = null;
                }
            }

            if ($data === null) {
                // Fallback: Prefer sanitized to avoid corrupted records on flaky devices
                if (method_exists($zk, 'getSanitizedAttendance')) {
                    $data = $zk->getSanitizedAttendance();
                } elseif (method_exists($zk, 'getAttendance')) {
                    $data = $zk->getAttendance();
                } else {
                    throw new \Exception('ZKTeCo device method getAttendance not found - package version mismatch');
                }

                // Manual client-side date filter (same as package's helper) if window requested
                if (($fromDate || $toDate) && is_array($data)) {
                    $from = $fromDate ? Carbon::parse($fromDate) : null;
                    $to = $toDate ? Carbon::parse($toDate) : null;
                    $data = array_filter($data, function ($row) use ($from, $to) {
                        $ts = $row['timestamp'] ?? $row['time'] ?? $row[3] ?? $row['datetime'] ?? null;
                        if (! $ts) {
                            return false;
                        }
                        try {
                            $t = Carbon::parse($ts);
                            if ($from && $t->lt($from)) {
                                return false;
                            }
                            if ($to && $t->gt($to)) {
                                return false;
                            }

                            return true;
                        } catch (\Throwable $_) {
                            return false;
                        }
                    });
                    $data = array_values($data); // reindex after filter
                    Log::info('ZK manual date filter applied', ['device' => $device->id, 'from' => $fromDate, 'to' => $toDate, 'filtered_count' => count($data)]);
                }
            }

            try {
                if (method_exists($zk, 'enableDevice')) {
                    $zk->enableDevice();
                    Log::info('Device re-enabled after sync', ['device' => $device->id]);
                }
            } catch (\Throwable $_) {
            }
            $zk->disconnect();

            if (! is_array($data)) {
                throw new \Exception('Unexpected response from device - expected array, got '.gettype($data));
            }

            // Guard against huge datasets that cause 60s fatal on processing
            if (count($data) > 5000) {
                Log::warning('Large attendance dataset - consider using date filter (Today / Last 3 days) instead of All', ['device' => $device->id, 'count' => count($data), 'suggestion' => 'Use incremental sync (auto since last_sync_at) with date filter']);
            }

            $mapped = [];
            foreach ($data as $row) {
                if (! is_array($row)) {
                    Log::warning('Unexpected ZK row format', ['device' => $device->id, 'row' => $row]);

                    continue;
                }
                // Handle both associative and numeric indexed formats
                // Real device returns: [0=>uid, 1=>id, 2=>state, 3=>timestamp, 4=>type] OR associative
                $mapped[] = [
                    'uid' => $row['uid'] ?? $row[0] ?? $row['UID'] ?? null,
                    'id' => $row['id'] ?? $row['userid'] ?? $row['user_id'] ?? $row[1] ?? null,
                    'state' => $row['state'] ?? $row[2] ?? null,
                    'timestamp' => $row['timestamp'] ?? $row['time'] ?? $row[3] ?? $row['datetime'] ?? null,
                    'type' => $row['type'] ?? $row[4] ?? null,
                    '_raw' => $row, // keep for debugging
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            try {
                if (method_exists($zk, 'enableDevice')) {
                    $zk->enableDevice();
                }
            } catch (\Throwable $_) {
            }
            try {
                $zk->disconnect();
            } catch (\Throwable $_) {
            }
            // Distinguish timeout vs other errors for user-friendly message
            $msg = $e->getMessage();
            if (stripos($msg, 'Maximum execution time') !== false || stripos($msg, 'timed out') !== false) {
                throw new \Exception("Device {$ip}:{$port} timed out (Util.php:376). Device has too many logs (".(isset($data) ? count($data) : '?').") or firmware busy. HTTP timeout 20s hit. Fix: queue the job (default in UI) or run CLI with longer timeout: \n  php artisan hrm:sync-attendance --device={$device->id} --since=today --queue\n  php artisan queue:work --timeout=90\nOriginal: ".$msg);
            }
            throw $e;
        }
    }

    /**
     * Whether this process may use simulated attendance data at all.
     *
     * True on a local/testing/development host, or where a deployment has explicitly opted in with
     * `hrm.sync.allow_simulation`. Never true because of an IP address: three hardcoded 192.168.10.9x
     * comparisons used to stand in for "this is a demo device", which on a production host meant a
     * customer using that common private range received fabricated punch rows in the table payroll
     * reads. Report item 46 / P1.8.
     */
    private function simulationAllowed(): bool
    {
        return app()->environment('local', 'testing', 'development')
            || (bool) config('hrm.sync.allow_simulation', false);
    }

    private function shouldUseDemoFallback(BiometricDevice $device, \Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        $isLocal = $this->simulationAllowed();
        $isNetworkError = str_contains($msg, 'unreachable') || str_contains($msg, 'timed out') || str_contains($msg, 'util.php') || str_contains($msg, 'maximum execution') || str_contains($msg, 'connect');

        return $isLocal && $isNetworkError;
    }

    /**
     * Turn raw device records into `attendance_logs` rows. Used by the full sync and by Stage 1 alike, so
     * the two paths cannot drift apart.
     *
     * Bounded on three axes at once, because a device with a big backlog defeated each of them in turn:
     *
     *  - **time**: `$deadline` (from `hrm.sync.budget_seconds`) is checked per record while normalizing and
     *    per chunk while persisting, so an unfinished batch stops between rows and reports `partial`
     *    instead of being killed;
     *  - **statements**: the old loop ran three queries per punch — `Employee` by `biometric_id`,
     *    `DeviceUser` by `device_user_id`, and an `exists()` duplicate check — so a 50 000-log device cost
     *    ~150 000 statements. This runs 2 per 1 000 distinct ids and 1 per 500 candidate rows;
     *  - **memory**: never more than one chunk of database rows in hand. A first cut at this read *all*
     *    of a device's existing punches at once, which on a busy device is a 100 000-row Eloquent
     *    collection (every row cast to Carbon) — the same outage with a different error message.
     *
     * The duplicate check deliberately uses the base builder rather than `AttendanceLog`: the model casts
     * `punch_time`, and comparing "already stored" only needs the string as the database holds it.
     *
     * @param  array<int, mixed>  $fetched
     * @return array{inserted: int, skipped_duplicate: int, unmapped: int, partial: bool}
     */
    private function ingestDeviceLogs(BiometricDevice $device, array $fetched, float $deadline): array
    {
        $inserted = 0;
        $skippedDuplicate = 0;
        $unmapped = 0;
        $partial = false;

        // Pass 1 — normalize. No I/O: a device record is meaningless until it has a device user and a time
        // the rest of the system can compare, and both are the device's choice, not ours.
        $candidates = [];
        $accepted = [];

        foreach ($fetched as $r) {
            if (microtime(true) > $deadline) {
                $partial = true;
                Log::warning('Sync ingest stopped at its time budget', ['device' => $device->id, 'fetched' => count($fetched), 'normalized' => count($candidates)]);

                break;
            }

            // Package can return multiple formats: associative with uid/id/timestamp or numeric indexed
            $deviceUserId = $r['id'] ?? $r['userid'] ?? $r['user_id'] ?? $r[1] ?? null; // FIX: use ZK UserID (id) only, NOT uid (internal index)
            $rawTimestamp = $r['timestamp'] ?? $r['time'] ?? $r[3] ?? $r['datetime'] ?? null;

            if (! $deviceUserId || ! $rawTimestamp) {
                Log::warning('ZK raw record missing uid/timestamp', ['device' => $device->id, 'raw' => $r]);

                continue;
            }

            try {
                $punchTime = Carbon::parse($rawTimestamp)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                Log::warning('Invalid timestamp from device', ['device' => $device->id, 'raw' => $rawTimestamp]);

                continue;
            }

            $key = (string) $deviceUserId.'|'.$punchTime;

            if (isset($accepted[$key])) {
                // Two records for the same second from the device itself: the old per-row `exists()` saw the
                // earlier one because it had already been inserted, so it skipped. Same here, no round trip.
                $skippedDuplicate++;

                continue;
            }

            $accepted[$key] = true;
            $candidates[] = ['device_user_id' => (string) $deviceUserId, 'punch_time' => $punchTime];
        }

        if ($candidates === []) {
            return ['inserted' => 0, 'skipped_duplicate' => $skippedDuplicate, 'unmapped' => 0, 'partial' => $partial];
        }

        // Device id -> employee, resolved once for the batch. `employees.biometric_id` first (it is the
        // editable, authoritative mapping in the Employee form), `device_users` as the fallback: one ZK id
        // works on any device, so a punch-in on A and a punch-out on B resolve to the same employee.
        $byBiometric = [];
        $byDeviceUser = [];
        $ids = array_values(array_unique(array_column($candidates, 'device_user_id')));

        foreach (array_chunk($ids, self::MAPPING_CHUNK) as $chunk) {
            $byBiometric += Employee::query()->whereIn('biometric_id', $chunk)->pluck('employee_id', 'biometric_id')->all();
            $byDeviceUser += DeviceUser::query()->whereIn('device_user_id', $chunk)->pluck('employee_id', 'device_user_id')->all();
        }

        // Pass 2 — persist in chunks: one read of what already exists, one insert of what does not.
        foreach (array_chunk($candidates, self::INGEST_CHUNK) as $chunk) {
            if (microtime(true) > $deadline) {
                $partial = true;
                Log::warning('Sync persistence stopped at its time budget', ['device' => $device->id, 'inserted_so_far' => $inserted]);

                break;
            }

            $wanted = [];
            foreach ($chunk as $c) {
                $wanted[$c['device_user_id'].'|'.$c['punch_time']] = true;
            }

            $already = DB::table('attendance_logs')
                ->where('device_id', $device->id)
                ->whereIn('device_user_id', array_values(array_unique(array_column($chunk, 'device_user_id'))))
                ->whereIn('punch_time', array_values(array_unique(array_column($chunk, 'punch_time'))))
                ->get(['device_user_id', 'punch_time']);

            foreach ($already as $row) {
                $k = (string) $row->device_user_id.'|'.(string) $row->punch_time;

                if (isset($wanted[$k])) {
                    unset($wanted[$k]);
                    $skippedDuplicate++;
                }
            }

            $rows = [];

            foreach ($chunk as $c) {
                $k = $c['device_user_id'].'|'.$c['punch_time'];

                if (! isset($wanted[$k])) {
                    continue;
                }

                $employeeId = $byBiometric[$c['device_user_id']] ?? $byDeviceUser[$c['device_user_id']] ?? null;

                if ($employeeId === null) {
                    // Count unmapped for debug - but still insert (a punch from an unknown id is evidence,
                    // and it becomes pairable the moment somebody maps that id).
                    $unmapped++;

                    if ($unmapped <= 3) {
                        Log::info('Unmapped device_user_id - still inserting log', ['device' => $device->id, 'device_user_id' => $c['device_user_id'], 'punch_time' => $c['punch_time'], 'note' => 'Map in Device Users to create pairs']);
                    }
                }

                $rows[] = [
                    'device_user_id' => $c['device_user_id'],
                    'employee_id' => $employeeId,
                    'punch_time' => $c['punch_time'],
                    'device_id' => $device->id,
                    'punch_type' => 'AUTO',
                    'is_processed' => false,
                ];
            }

            if ($rows !== []) {
                $inserted += $this->logs->createMany($rows);
            }
        }

        return ['inserted' => $inserted, 'skipped_duplicate' => $skippedDuplicate, 'unmapped' => $unmapped, 'partial' => $partial];
    }

    private function generateDemoAttendance(BiometricDevice $device, ?string $fromDate, ?string $toDate): array
    {
        $demoUsers = [
            ['uid' => '101', 'id' => '101', 'name' => 'TANBHIR HOSSAIN'],
            ['uid' => '102', 'id' => '102', 'name' => 'Rimon Mia'],
        ];
        $from = $fromDate ? Carbon::parse($fromDate) : now()->subHours(24);
        $to = $toDate ? Carbon::parse($toDate) : now();
        if ($from->diffInHours($to) > 72) {
            $from = $to->copy()->subHours(24);
        }
        $rows = [];
        foreach ($demoUsers as $u) {
            $base = now()->toDateString();
            $in = Carbon::parse($base.' 09:05:00');
            $out = Carbon::parse($base.' 18:02:00');
            if ($in->between($from, $to)) {
                $rows[] = ['uid' => $u['uid'], 'id' => $u['id'], 'state' => 0, 'timestamp' => $in->format('Y-m-d H:i:s'), 'type' => 0, '_demo' => true];
            }
            if ($out->between($from, $to)) {
                $rows[] = ['uid' => $u['uid'], 'id' => $u['id'], 'state' => 1, 'timestamp' => $out->format('Y-m-d H:i:s'), 'type' => 0, '_demo' => true];
            }
            $yIn = Carbon::parse(now()->subDay()->toDateString().' 09:03:00');
            $yOut = Carbon::parse(now()->subDay()->toDateString().' 17:55:00');
            if ($yIn->between($from, $to)) {
                $rows[] = ['uid' => $u['uid'], 'id' => $u['id'], 'state' => 0, 'timestamp' => $yIn->format('Y-m-d H:i:s'), 'type' => 0, '_demo' => true];
            }
            if ($yOut->between($from, $to)) {
                $rows[] = ['uid' => $u['uid'], 'id' => $u['id'], 'state' => 1, 'timestamp' => $yOut->format('Y-m-d H:i:s'), 'type' => 0, '_demo' => true];
            }
        }
        Log::info('Generated DEMO attendance', ['device' => $device->id, 'count' => count($rows), 'from' => $from, 'to' => $to]);

        return $rows;
    }

    /** Quick TCP reachability test — soft check only, never blocks ZK attempt for demo/local */
    private function isReachable(BiometricDevice $device, int $timeout = 3): bool
    {
        $host = $device->ip_address;
        $port = $device->port ?? 4370;
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            Log::info('Device reachable via TCP', ['device' => $device->id, 'host' => $host, 'port' => $port]);

            return true;
        }
        // Always allow the ZK UDP attempt where simulation is permitted, even if TCP failed: real ZK
        // devices speak UDP and fsockopen can fail on a device that is online. This used to be decided
        // by matching $host against three hardcoded demo IPs, which made a reachability guard depend on
        // an address a seeder happened to pick.
        if ($this->simulationAllowed()) {
            Log::info('fsockopen failed but allowing ZK UDP attempt (demo/local)', ['device' => $device->id, 'host' => $host, 'port' => $port, 'errno' => $errno, 'errstr' => $errstr]);

            return true;
        }
        // Only hard-fail for definite unreachable errors on non-demo IPs
        if (in_array($errno, [111, 110, 113, 101, 10060, 10061])) {
            Log::warning('Device unreachable (hard fail)', ['device' => $device->id, 'host' => $host, 'port' => $port, 'errno' => $errno, 'errstr' => $errstr]);

            return false;
        }
        Log::info('fsockopen soft-fail, allowing ZK UDP attempt', ['device' => $device->id, 'host' => $host, 'port' => $port, 'errno' => $errno, 'errstr' => $errstr]);

        return true;
    }

    /**
     * Fetch users from device - for mapping device_user_id <-> employee
     */
    public function fetchUsers(BiometricDevice $device): array
    {
        if (! class_exists('\Jmrashed\Zkteco\Lib\ZKTeco')) {
            return [];
        }

        if (! $this->isReachable($device, 3)) {
            throw new \Exception("Device {$device->ip_address}:{$device->port} unreachable - cannot fetch users");
        }

        @ini_set('default_socket_timeout', '3'); // bounded I/O, not a shorter PHP time limit

        $zkClass2 = null;
        foreach (['\Jmrashed\Zkteco\Lib\ZKTeco', '\TahaRafi\Zkteco\Lib\ZKTeco'] as $c2) {
            if (class_exists($c2)) {
                $zkClass2 = $c2;
                break;
            }
        } if (! $zkClass2) {
            $zkClass2 = '\Jmrashed\Zkteco\Lib\ZKTeco';
        } $zk = new $zkClass2($device->ip_address, $device->port ?? 4370);
        if (! $zk->connect()) {
            throw new \Exception("Cannot connect to {$device->ip_address} - device busy or unreachable");
        }
        try {
            $users = method_exists($zk, 'getUser') ? $zk->getUser() : [];
            $zk->disconnect();

            return is_array($users) ? $users : [];
        } catch (\Throwable $e) {
            try {
                if (method_exists($zk, 'enableDevice')) {
                    $zk->enableDevice();
                }
            } catch (\Throwable $_) {
            }
            try {
                $zk->disconnect();
            } catch (\Throwable $_) {
            }
            throw $e;
        }
    }
}

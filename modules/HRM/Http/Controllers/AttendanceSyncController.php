<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Interfaces\Attendance\AttendanceSyncRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendancePipeline;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\Attendance\AttendanceSyncService;

class AttendanceSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceSyncService $sync,
        private readonly AttendanceProcessingService $processor,
        private readonly AttendanceSyncRepositoryInterface $syncRepo,
        private readonly AttendancePipeline $pipeline
    ) {}

    public function index(): Response
    {
        $devices = BiometricDevice::with('syncSetting')->orderBy('name')->get();
        $logs = $this->syncRepo->recentLogs(20);

        return Inertia::render('HRM::AttendanceSync/Index', [
            'devices' => $devices->map(fn(BiometricDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'ip_address' => $d->ip_address,
                'port' => $d->port,
                'status' => $d->status,
                'is_enabled' => $d->is_enabled,
                'auto_sync_enabled' => $d->auto_sync_enabled,
                'sync_interval_minutes' => $d->sync_interval_minutes,
                'last_sync_at' => $d->last_sync_at?->toIso8601String(),
                'syncSetting' => $d->syncSetting?->toArray(),
            ])->values()->all(),
            'logs' => $logs->map(fn($l) => [
                'id' => $l->id,
                'device' => ($l->device?->name ?? 'Unknown'),
                'status' => $l->status,
                'records_fetched' => $l->records_fetched,
                'records_processed' => $l->records_processed,
                'message' => $l->message,
                'error' => $l->error,
                'synced_at' => $l->synced_at?->toIso8601String(),
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function syncDevice(Request $request, BiometricDevice $device): RedirectResponse
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date', 'before_or_equal:to_date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $from = $data['from_date'] ?? $data['from'] ?? $request->input('from_date');
        $to = $data['to_date'] ?? $data['to'] ?? $request->input('to_date');
        $useQueue = (bool) ($data['queue'] ?? $request->input('queue', false));

        // Queue mode is OPT-IN ( ?queue=1 ) — web default is direct sync so user sees immediate result without needing queue:work
        if ($useQueue) {
            try {
                \Modules\HRM\Jobs\SyncDeviceJob::dispatch($device->id, auth()->id(), $from, $to);
                $range = ($from || $to) ? " [{$from} → {$to}]" : " [incremental]";
                return back()->with('success', "Sync queued for {$device->name} ({$device->ip_address}:{$device->port}){$range}. Run: php -d max_execution_time=0 artisan queue:work --timeout=300 if needed. Or use direct sync without ?queue=1.");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Queue dispatch failed, falling back to direct sync', ['device' => $device->id, 'error' => $e->getMessage()]);
            }
        }

        // Unified Sync = Pipeline: sync log → pair → daily with date filter (professional)
        // Previously Sync did raw+partial pair only; now delegates to Pipeline for full flow
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');
        try {
            $result = $this->pipeline->runForDevice($device, $from, $to);
            if ($result['ok']) {
                return back()->with('success', $result['message']);
            }
            $stage1 = $result['stages']['stage1_raw'] ?? null;
            if (($stage1['ok'] ?? true) === false) {
                return back()->with('error', $stage1['message'] ?? $result['message'] ?? 'Sync failed. Check device connectivity. Try Test Connection first.');
            }
            return back()->with('warning', $result['message'] ?? 'Pipeline partial — check logs.');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Maximum execution time') !== false || stripos($msg, 'Util.php') !== false || stripos($msg, 'timed out') !== false) {
                $msg = "Device {$device->name} ({$device->ip_address}:{$device->port}) timed out after 20-60s (vendor Util.php:376). Device transfers FULL log even with date filter. Solutions: 1) Ensure device not overloaded — try CLI php artisan hrm:sync-attendance --device={$device->id} --since=today --queue then queue:work, 2) Check firewall/UDP, 3) If no device, use Manual Punch. Original: ".$e->getMessage();
            }
            return back()->with('error', $msg);
        }
    }

    public function syncAll(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'queue' => ['nullable', 'boolean'],
        ]);
        $from = $data['from_date'] ?? $data['from'] ?? $request->input('from_date');
        $to = $data['to_date'] ?? $data['to'] ?? $request->input('to_date');
        // For sync-all, default to QUEUE to avoid 60s* N timeout
        // User reported sync-all fails at 60s even with direct sync (multiple devices sequentially)
        // Opt-out with ?queue=0 for small setups
        $useQueue = $request->has('queue') ? (bool) $request->input('queue') : true;

        if ($useQueue) {
            $devices = BiometricDevice::where('is_enabled', true)->get();
            if ($devices->isEmpty()) {
                return back()->with('warning', 'No enabled devices found for sync.');
            }
            foreach ($devices as $d) {
                \Modules\HRM\Jobs\SyncDeviceJob::dispatch($d->id, auth()->id(), $from, $to);
            }
            $range = ($from || $to) ? " [{$from} → {$to}]" : " [incremental]";
            return back()->with('success', "Queued sync for {$devices->count()} device(s){$range}. Run: php -d max_execution_time=0 artisan queue:work --timeout=300 --sleep=3 to process (CLI needs 0 timeout, not 60). Or add ?queue=0 for direct sync (may timeout).");
        }

        // Unified: syncAll = pipeline runForAll (full pipeline per device)
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');
        $results = $this->pipeline->runForAll($from, $to);
        if (empty($results)) {
            return back()->with('warning', 'No enabled devices found for sync.');
        }
        $ok = collect($results)->filter(fn($r) => $r['ok'])->count();
        $total = count($results);
        $failed = $total - $ok;
        if ($failed > 0) {
            $firstError = collect($results)->first(fn($r) => !$r['ok'])['message'] ?? '';
            $msg = "Synced {$ok}/{$total} devices (pipeline). {$failed} failed — check sync logs.";
            if ($firstError) $msg .= " First error: {$firstError}";
            return back()->with('warning', $msg);
        }
        return back()->with('success', "Successfully synced {$ok}/{$total} devices (pipeline: Raw→Pairs→Daily).");
    }

    public function processPending(Request $request): RedirectResponse
    {
        $limit = min((int) $request->input('limit', 1000), 5000);
        $result = $this->processor->processPending($limit);

        return back()->with('success', "Processed {$result['processed']} logs: {$result['new_pairs']} new pairs, {$result['closed_pairs']} closed, {$result['errors']} errors.");
    }

    public function updateSetting(Request $request, BiometricDevice $device): RedirectResponse
    {
        $data = $request->validate([
            'auto_sync_enabled' => ['required', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'sync_window_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Persist to dedicated settings table and mirror to device for quick lookup
        $this->syncRepo->upsertSetting($device->id, [
            'auto_sync_enabled' => $data['auto_sync_enabled'],
            'sync_interval_minutes' => $data['sync_interval_minutes'],
            'sync_window_hours' => $data['sync_window_hours'] ?? 24,
            'is_active' => $data['is_active'] ?? true,
            'last_sync_at' => now(),
            'next_sync_at' => now()->addMinutes((int) $data['sync_interval_minutes']),
        ]);

        $device->update([
            'auto_sync_enabled' => $data['auto_sync_enabled'],
            'sync_interval_minutes' => $data['sync_interval_minutes'],
        ]);

        return redirect()->route('hrm.attendance.sync.index')
            ->with('success', 'Sync settings updated successfully.');
    }

    public function manualPunch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'punch_time' => ['required', 'date'],
            'device_id' => ['nullable', 'exists:biometric_devices,id'],
        ]);

        // Use unique manual identifier per punch to avoid dedup collisions
        $manualUid = 'MANUAL-' . strtoupper(substr(md5($data['employee_id'].$data['punch_time'].now()->timestamp), 0, 8));

        AttendanceLog::create([
            'device_user_id' => $manualUid,
            'employee_id' => $data['employee_id'],
            'punch_time' => $data['punch_time'],
            'device_id' => $data['device_id'] ?? null,
            'punch_type' => 'AUTO',
            'is_processed' => false,
        ]);

        return back()->with('success', 'Manual punch added to processing queue.');
    }
}

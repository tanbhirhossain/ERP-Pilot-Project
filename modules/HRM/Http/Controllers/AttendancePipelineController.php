<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\Attendance\AttendancePipeline;

class AttendancePipelineController extends Controller
{
    public function __construct(private readonly AttendancePipeline $pipeline) {}

    public function run(Request $r, BiometricDevice $device)
    {
        $data = $r->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $useQueue = (bool) ($data['queue'] ?? false);
        if ($useQueue) {
            $this->pipeline->dispatchForDevice($device->id, $data['from'] ?? null, $data['to'] ?? null);
            return back()->with('success', "Pipeline queued for {$device->name} ({$device->ip_address}) → Stage1 Raw → Stage2 Pairs → Stage3 Daily. Run queue:work.");
        }

        @set_time_limit(300);
        $result = $this->pipeline->runForDevice($device, $data['from'] ?? null, $data['to'] ?? null);
        return back()->with($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    public function runAll(Request $r)
    {
        $data = $r->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $useQueue = $r->has('queue') ? (bool) $r->input('queue') : true;
        if ($useQueue) {
            $this->pipeline->dispatchForAll($data['from'] ?? null, $data['to'] ?? null);
            return back()->with('success', 'Pipeline queued for all devices (3 stages each). Run queue:work.');
        }

        @set_time_limit(300);
        $results = $this->pipeline->runForAll($data['from'] ?? null, $data['to'] ?? null);
        $ok = collect($results)->every(fn($r) => $r['ok']);
        $msg = collect($results)->map(fn($r) => $r['message'])->implode(' | ');
        return back()->with($ok ? 'success' : 'warning', $msg ?: 'Pipeline completed');
    }

    public function stage(Request $r, string $stage)
    {
        $data = $r->validate([
            'device' => ['nullable', 'exists:biometric_devices,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        switch ($stage) {
            case 'raw':
                if (empty($data['device'])) return back()->with('error', 'Device required for raw stage');
                $device = BiometricDevice::findOrFail($data['device']);
                $res = $this->pipeline->stage1SyncRaw($device, $from, $to);
                return back()->with($res['ok'] ? 'success' : 'error', $res['message'] ?? json_encode($res));
            case 'pairs':
                $res = $this->pipeline->stage2MakePairs(5000, $from, $to);
                return back()->with('success', "Pairs: processed {$res['processed']}, new {$res['new_pairs']}, closed {$res['closed_pairs']} [{$from}→{$to}]");
            case 'daily':
                $res = $this->pipeline->stage3PrepareAttendance($from, $to);
                return back()->with('success', "Daily recalculated: {$res['recalculated']} records");
            default:
                return back()->with('error', "Invalid stage $stage");
        }
    }
}

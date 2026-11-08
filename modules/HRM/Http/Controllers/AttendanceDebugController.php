<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

/**
 * Attendance Debug - diagnose why pairs/dailies are nil after sync
 */
class AttendanceDebugController extends Controller
{
    public function __construct(private readonly AttendanceProcessingService $processor) {}

    public function orphanStats()
    {
        $stats = $this->processor->orphanStats();

        return response()->json([
            'stats' => $stats,
            'diagnosis' => $this->diagnose($stats),
            'hint' => 'If orphan_unprocessed >0, your Device Users mapping is missing. Run Sync Users from Device or import Excel, then POST to /hrm/attendance/reprocess-orphans',
        ]);
    }

    public function reprocessOrphans(Request $r)
    {
        $limit = min((int) $r->input('limit', 1000), 5000);
        $result = $this->processor->reprocessOrphans($limit);
        if ($r->wantsJson()) {
            return response()->json($result);
        }

        return back()->with('success', "Reprocessed: backfilled {$result['backfilled']}, processed {$result['processed']} logs ({$result['new_pairs']} new pairs, {$result['closed_pairs']} closed). Orphans: {$result['orphans_found']}");
    }

    private function diagnose(array $s): string
    {
        if ($s['total_logs'] === 0) {
            return 'No logs yet — run Sync Device. Check device IP/port.';
        }
        if ($s['orphan_unprocessed'] > 0 && $s['device_users'] === 0) {
            return 'All logs orphan: Device Users table empty! Import Excel via php artisan db:seed --class=RealEmployeeBasicSeeder or Sync Users from Device (HRM → Device Users → Sync).';
        }
        if ($s['orphan_unprocessed'] > 0) {
            return "Orphan logs: {$s['orphan_unprocessed']} logs have device_user_id not in Device Users mapping. Sample orphan IDs: ".implode(', ', $s['sample_orphan_device_ids']).'. Sample mapped: '.implode(', ', $s['sample_mapped_device_ids']).'. Map them in HRM → Device Users, then reprocess.';
        }
        if ($s['orphan_processed_expired'] > 0) {
            return 'Some orphans auto-expired after 7d (marked processed). Reprocess will backfill if mapping now exists.';
        }
        if ($s['pairs'] === 0 && $s['mapped_logs'] > 1) {
            return 'Mapped logs exist but no pairs: check punch_time order and findOpenPair 72h window. Try reprocess with limit 5000.';
        }
        if ($s['pairs'] > 0 && $s['dailies'] === 0) {
            return 'Pairs exist but no dailies: upsertForPair may have failed. Check attendance_dailies table.';
        }
        if ($s['pairs'] > 0) {
            return 'Healthy: pairs are being created. Check HRM → Attendance Pairs with date filter.';
        }

        return 'Unknown state - check logs table.';
    }
}

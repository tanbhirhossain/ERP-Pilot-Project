<?php

namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

class ReprocessOrphansCommand extends Command
{
    protected $signature = 'hrm:reprocess-orphans {--limit=1000 : Max logs to process} {--stats : Show stats only} {--reset-expired : Reset 7d+ expired orphans to retry (use after fixing mapping)}';
    protected $description = 'Backfill orphan attendance logs after DeviceUser mapping and create pairs/dailies';

    public function handle(AttendanceProcessingService $processor): int
    {
        if ($this->option('stats')) {
            $stats = $processor->orphanStats();
            $this->table(['Metric', 'Value'], collect($stats)->map(fn($v,$k)=>[$k, is_array($v)? json_encode($v): $v])->values()->toArray());
            return 0;
        }

        $limit = (int) $this->option('limit');
        if ($this->option('reset-expired')) {
            $reset = \Modules\HRM\Models\Attendance\AttendanceLog::whereNull('employee_id')->where('is_processed', true)->update(['is_processed' => false]);
            $this->info("Reset $reset expired orphans to unprocessed (will retry after mapping fix)");
        }
        $this->info("Reprocessing orphans (limit $limit)...");
        $result = $processor->reprocessOrphans($limit);

        $this->info("Backfilled: {$result['backfilled']} | Orphans: {$result['orphans_found']}");
        $this->info("Processed: {$result['processed']} | New pairs: {$result['new_pairs']} | Closed: {$result['closed_pairs']} | Errors: {$result['errors']}");
        if (($result['skipped_orphan'] ?? 0) > 0) {
            $this->warn("Still orphan (need mapping): {$result['skipped_orphan']}");
        }
        return 0;
    }
}

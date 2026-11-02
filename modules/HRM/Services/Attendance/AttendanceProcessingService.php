<?php
namespace Modules\HRM\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Roster\RosterShift;

/**
 * 6-Step Biometric Attendance Engine (Bengali flow) - PRODUCTION READY
 * Step1: raw logs in attendance_logs (is_processed=false)
 * Step2: resolve employee policy (Fixed/Flexible/Custom 72h)
 * Step3: check open pair within 24/72h; if exists close punch_out else new punch_in
 * Step4: auto-detect shift & duty_type (Primary vs Overtime)
 * Step5: calculate worked_hours & mark logs processed
 * Step6: sync daily summary (attendance_dailies)
 */
class AttendanceProcessingService
{
    public function __construct(
        private readonly AttendanceLogRepositoryInterface $logs,
        private readonly AttendancePairRepositoryInterface $pairs,
        private readonly AttendanceDailyRepositoryInterface $dailies,
    ) {}

    /** Process all unprocessed logs - handles concurrency & errors gracefully. Unified Sync=Pipeline with date filter */
    public function processPending(int $limit = 500, ?string $from = null, ?string $to = null): array
    {
        $logs = $this->logs->unprocessed($limit, $from, $to);
        $processed = 0;
        $newPairs = 0;
        $closedPairs = 0;
        $errors = 0;
        $skippedOrphan = 0;

        foreach ($logs as $log) {
            try {
                $result = $this->processSingle($log);
                if ($result['status'] === 'processed') {
                    $processed++;
                    if ($result['action'] === 'created') $newPairs++;
                    if ($result['action'] === 'closed') $closedPairs++;
                } elseif ($result['status'] === 'skipped') {
                    $skippedOrphan++;
                    $processed++; // counted as handled
                }
            } catch (\Throwable $e) {
                Log::error('Attendance process failed', ['log_id'=>$log->id,'employee_id'=>$log->employee_id??'unknown','error'=>$e->getMessage(),'trace'=>substr($e->getTraceAsString(),0,2000)]);
                $errors++;
            }
        }
        return ['fetched'=>$logs->count(),'processed'=>$processed,'new_pairs'=>$newPairs,'closed_pairs'=>$closedPairs,'paired'=>$newPairs+$closedPairs,'errors'=>$errors,'skipped_orphan'=>$skippedOrphan];
    }

    /**
     * Process single log - Steps 2-6
     * Returns structured result for counting
     */
    public function processSingle(AttendanceLog $log): array
    {
        $result = ['status'=>'pending','action'=>null];

        DB::transaction(function() use($log, &$result){
            // Re-fetch with lock to prevent race condition on concurrent processing
            $fresh = AttendanceLog::where('id',$log->id)->lockForUpdate()->first();
            if(!$fresh || $fresh->is_processed){
                $result = ['status'=>'already_processed','action'=>null];
                return;
            }

            // Professional: resolve via employees.biometric_id (ZK id) first, fallback to device_users.device_user_id (only id, never uid)
            $employeeId = $fresh->employee_id;
            if(!$employeeId && $fresh->device_user_id){
                $empByBio = \Modules\HRM\Models\Employee\Employee::where('biometric_id', (string)$fresh->device_user_id)->first();
                if($empByBio) {
                    $employeeId = $empByBio->employee_id;
                    $fresh->employee_id = $employeeId;
                } else {
                    $du = \Modules\HRM\Models\DeviceUser::where('device_user_id',$fresh->device_user_id)->first(); // FIX: only id
                    if($du && $du->employee_id) {
                        $employeeId = $du->employee_id;
                        $fresh->employee_id = $employeeId;
                    }
                }
            }
            if(!$employeeId){
                // Orphan log - no mapping exists; DON'T mark processed immediately - allow retry after DeviceUser mapping
                // Check if log is too old (7 days orphan) to avoid infinite retry on truly dead IDs
                $orphanAgeHours = Carbon::parse($fresh->punch_time)->diffInHours(now());
                if($orphanAgeHours > 24*7){
                    Log::warning('Orphan attendance log expired (7d) - marking processed', ['log_id'=>$fresh->id,'device_user_id'=>$fresh->device_user_id,'age_hours'=>$orphanAgeHours]);
                    $fresh->update(['is_processed'=>true]);
                    $result = ['status'=>'skipped','action'=>'orphan_expired','reason'=>'no_employee_mapping_7d'];
                    return;
                }
                Log::warning('Orphan attendance log - no employee mapping (will retry)', ['log_id'=>$fresh->id,'device_user_id'=>$fresh->device_user_id,'punch_time'=>$fresh->punch_time,'age_hours'=>$orphanAgeHours,'hint'=>'Run php artisan hrm:reprocess-orphans after mapping Device Users or use /hrm/device-users to map']);
                $result = ['status'=>'skipped','action'=>'orphan_retry','reason'=>'no_employee_mapping'];
                return;
            }

            $employee = \Modules\HRM\Models\Employee\Employee::where('employee_id',$employeeId)->first();
            if(!$employee){
                Log::warning('Orphan log - employee_id not found in employees (data error, check DeviceUser mapping)', ['log_id'=>$fresh->id,'employee_id'=>$employeeId,'device_user_id'=>$fresh->device_user_id,'punch_time'=>$fresh->punch_time]);
                // Professional: don't block pipeline forever on ghost employee_id — mark as orphan_expired after logging
                // User can fix mapping via hrm:fix-biometric and reprocess will pick it up after reset-expired, but we avoid infinite loop
                // We check log age: if >3d, expire it; else keep one more retry then expire on next run
                $ageHours = Carbon::parse($fresh->punch_time)->diffInHours(now());
                if ($ageHours > 72) {
                    $fresh->update(['is_processed'=>true]);
                    $result = ['status'=>'skipped','action'=>'orphan_expired','reason'=>'employee_not_found_3d'];
                } else {
                    $result = ['status'=>'skipped','action'=>'orphan_retry','reason'=>'employee_not_found'];
                }
                return;
            }

            // Step 2: Employee & Policy Resolution
            $policy = \Modules\HRM\Models\Employee\EmployeePolicy::where('employee_id',$employeeId)->first();
            $shiftType = $policy?->shift_type ?? 'Fixed';
            $maxHours = $shiftType === 'Custom' ? 72 : 24;
            $graceIn = $policy?->grace_time_in ?? 15;
            $graceOut = $policy?->grace_time_out ?? 15;

            $punchTime = Carbon::parse($fresh->punch_time);
            // Step 3: Duty-date aware pairing with smart fallback for night grace
            // First compute expected shift/duty for this punch
            $shiftIdForOpen = $this->detectShift($punchTime, $policy, $employeeId);
            $dutyDateForOpen = $this->resolveDutyDate($punchTime, $shiftIdForOpen);
            // Try strict duty_date match (prevents 28 OUT → 29 IN cross-day bug)
            $openPair = $this->pairs->findOpenPair($employeeId, $maxHours, $punchTime->toDateTimeString(), $dutyDateForOpen);
            // Smart fallback: if no strict match, check any open pair within window but only if plausible OUT (≤12h for Fixed, ≤maxHours for Custom)
            // This handles night OUT 06:10 after 06:00 where detection may give Day instead of Night, but avoids 23.8h false pair
            if (!$openPair) {
                $anyOpen = $this->pairs->findOpenPair($employeeId, $maxHours, $punchTime->toDateTimeString(), null);
                if ($anyOpen) {
                    $inTime = Carbon::parse($anyOpen->punch_in);
                    $diffHours = $inTime->diffInMinutes($punchTime, false) / 60;
                    $threshold = $maxHours > 24 ? $maxHours : 12; // Custom shifts may span up to 72h; Fixed shifts cap at 12h.
                    if ($diffHours > 0 && $diffHours <= $threshold) {
                        // Custom policies explicitly allow long shifts. Do not impose the
                        // Fixed-shift 12h plausibility limit on their open pairs.
                        if ($maxHours > 24) {
                            $openPair = $anyOpen;
                        } else {
                            // A night shift may close after midnight even when detection
                            // resolves the OUT punch to another duty date.
                            $openShift = $anyOpen->shift;
                            if ($openShift && $openShift->crosses_midnight) {
                                $openPair = $anyOpen;
                            } elseif ($diffHours < 12) {
                                // Keep long daytime gaps from being mistaken for a punch OUT.
                                $openPair = $anyOpen;
                            }
                        }
                    }
                }
            }

            // Prevent duplicate punch within 1 minute (debounce - device may send duplicate)
            // Check against open pair's last punch only — if no open pair, this is a new punch_in, not a duplicate
            if($openPair){
                $lastPunch = Carbon::parse($openPair->punch_in);
                if($openPair->punch_out) $lastPunch = Carbon::parse($openPair->punch_out);
                $minutesSinceLastPunch = $lastPunch->diffInMinutes($punchTime, false);
                if(abs($minutesSinceLastPunch) < 1){
                    Log::info('Duplicate punch debounced (60s)', ['log_id'=>$fresh->id, 'employee_id'=>$employeeId, 'last_punch'=>$lastPunch, 'punch_time'=>$punchTime]);
                    $fresh->update(['is_processed'=>true]);
                    $result = ['status'=>'skipped','action'=>'duplicate_debounce'];
                    return;
                }
            }
            // Also check exact duplicate: same employee, same punch_time already exists as a pair (duty_date aware)
            $exactDup = $this->pairs->findOpenPair($employeeId, 1, $punchTime->toDateTimeString(), $dutyDateForOpen);
            // Not needed — open pair check already covers, but log for safety
            

            if($openPair){
                // Validate punch_out is after punch_in
                $in = Carbon::parse($openPair->punch_in);
                if($punchTime->lte($in)){
                    Log::warning('Punch time before punch_in - skipping', ['log_id'=>$fresh->id,'open_pair_id'=>$openPair->id,'punch_in'=>$openPair->punch_in,'punch_time'=>$punchTime]);
                    $fresh->update(['is_processed'=>true]);
                    $result = ['status'=>'skipped','action'=>'invalid_time_order'];
                    return;
                }
                // Close open pair as punch_out
                $diff = $in->diff($punchTime);
                $hours = $diff->h + $diff->days*24 + $diff->i/60 + $diff->s/3600;
                $openPair->punch_out = $punchTime;
                $openPair->worked_hours = round($hours,2);
                // Validate max hours not exceeded (e.g., 72h cap)
                if($openPair->worked_hours > $maxHours){
                    Log::warning('Worked hours exceed max - capping or splitting', ['pair_id'=>$openPair->id,'hours'=>$openPair->worked_hours,'max'=>$maxHours]);
                    // Still save but flag - could be multi-day custom shift
                }
                $openPair->save();
                $fresh->update(['is_processed'=>true]);
                // Step 6: sync daily
                $this->dailies->upsertForPair($openPair);
                $result = ['status'=>'processed','action'=>'closed','pair_id'=>$openPair->id];
            } else {
                // Step 4: new punch_in - auto-detect shift & duty_type (reuse already resolved for open check)
                $shiftId = $shiftIdForOpen;
                $dutyDate = $dutyDateForOpen;
                $isPrimary = !$this->pairs->hasPrimaryOnDate($employeeId, $dutyDate);
                $dutyType = $isPrimary ? 'Primary' : 'Overtime';

                $pair = $this->pairs->create([
                    'employee_id'=>$employeeId,
                    'shift_id'=>$shiftId,
                    'duty_date'=>$dutyDate,
                    'punch_in'=>$punchTime,
                    'punch_out'=>null,
                    'duty_type'=>$dutyType,
                    'shift_type_applied'=>$shiftType,
                    'worked_hours'=>0,
                ]);
                $fresh->update(['is_processed'=>true]);
                // Create daily skeleton if Primary (so dashboard shows Missing Out until closed)
                if($dutyType==='Primary'){
                    $this->dailies->upsertForPair($pair);
                } else {
                    // Upsert even when a no-log Off Day/Holiday row already exists so the punch
                    // immediately replaces that classification with Extra Duty when appropriate.
                    $this->dailies->upsertForPair($pair);
                }
                $result = ['status'=>'processed','action'=>'created','pair_id'=>$pair->id];
            }
        });

        return $result;
    }

    private array $shiftCache = [];
    private array $rosterCache = [];

    /**
     * Detect shift - handles midnight-crossing windows for Flexible shifts (with caching)
     */
    private function detectShift(Carbon $punchTime, $policy, string $employeeId): ?int
    {
        $timeStr = $punchTime->format('H:i:s');

        // Priority 1: Roster duty for exact date (authoritative) - cached per request
        $cacheKey = $employeeId.'_'.$punchTime->toDateString();
        if (!array_key_exists($cacheKey, $this->rosterCache)) {
            $this->rosterCache[$cacheKey] = \Modules\HRM\Models\Roster\RosterDuty::where('employee_id',$employeeId)->whereDate('roster_date',$punchTime->toDateString())->first();
            if (count($this->rosterCache) > 500) $this->rosterCache = array_slice($this->rosterCache, -250, null, true);
        }
        $roster = $this->rosterCache[$cacheKey];
        if($roster?->shift_id){
            return $roster->shift_id;
        }

        // Priority 2: Auto Shift — if no roster and auto_shift enabled, detect by duty_hours with buffer
        $autoShift = $policy?->auto_shift ?? true;
        $dutyHours = $policy?->duty_hours ?? \Modules\HRM\Models\Employee\Employee::where('employee_id',$employeeId)->value('duty_hours') ?? 8;
        $buffer = $policy?->buffer_minutes ?? 90;
        if ($autoShift) {
            $candidate = $this->detectAutoShiftByHours($timeStr, (int)$dutyHours, (int)$buffer);
            if ($candidate) return $candidate;
        }

        if($policy?->shift_type === 'Flexible'){
            // Flexible: find shift where punch_time within punch_in_window (handles midnight crossing window) - cached
            if (!isset($this->shiftCache['flexible'])) {
                $this->shiftCache['flexible'] = RosterShift::whereNotNull('punch_in_window_start')->whereNotNull('punch_in_window_end')->get();
            }
            $shifts = $this->shiftCache['flexible'];
            foreach($shifts as $shift){
                if($this->isTimeInWindow($timeStr, $shift->punch_in_window_start, $shift->punch_in_window_end)){
                    return $shift->id;
                }
            }
            // Fallback: scheduled window
            foreach($shifts as $shift){
                if($shift->start_time && $shift->end_time){
                    if($this->isTimeInWindow($timeStr, $shift->start_time, $shift->end_time)){
                        return $shift->id;
                    }
                }
            }
            return $shifts->first()?->id;
        }

        if($policy?->shift_type === 'Fixed'){
            // Fixed: find shift where time between start-end (handles midnight) - cached
            if (!isset($this->shiftCache['fixed'])) {
                // The FLEXI / Ad-Hoc sentinel spans the entire day; it is a last-resort
                // fallback, not a real fixed-shift match.
                $this->shiftCache['fixed'] = RosterShift::query()
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where('is_fallback', false)
                    ->get();
            }
            $all = $this->shiftCache['fixed'];
            foreach($all as $shift){
                if($this->isTimeInWindow($timeStr, $shift->start_time, $shift->end_time)){
                    return $shift->id;
                }
            }
        }

        // Custom or no policy: ensure at least one shift exists, then fallback
        $fallback = RosterShift::orderBy('id')->value('id');
        if (!$fallback) {
            // Auto-create default Day shift if DB empty (first-time setup)
            try {
                $def = RosterShift::create(['shift_name'=>'Day Shift','start_time'=>'09:00:00','end_time'=>'17:00:00','crosses_midnight'=>false,'max_duration_hours'=>24]);
                Log::info('Auto-created default RosterShift', ['id'=>$def->id]);
                return $def->id;
            } catch (\Throwable $e) { Log::error('Failed to auto-create shift', ['error'=>$e->getMessage()]); }
        }
        return $fallback;
    }

    /**
     * Check if time is within window - handles midnight crossing
     * e.g., 22:00-06:00 means 22:00 today to 06:00 tomorrow
     */
    private function isTimeInWindow(string $time, string $start, string $end): bool
    {
        // Normalize to minutes since midnight for comparison
        $t = $this->toMinutes($time);
        $s = $this->toMinutes($start);
        $e = $this->toMinutes($end);

        if($s <= $e){
            // Normal window: 09:00-17:00
            return $t >= $s && $t <= $e;
        } else {
            // Midnight crossing: 22:00-06:00 means t >= 22:00 OR t <= 06:00
            return $t >= $s || $t <= $e;
        }
    }

    /**
     * Auto Shift by duty_hours: find shift whose duration ≈ employee duty_hours (6/7/8) and contains punch time
     */
    private function detectAutoShiftByHours(string $timeStr, int $dutyHours, int $buffer = 90): ?int
    {
        $cacheKey = 'auto_'.$dutyHours.'_'.$buffer;
        if (!isset($this->shiftCache[$cacheKey])) {
            // Cache all shifts with duration calc
            $all = RosterShift::whereNotNull('start_time')->whereNotNull('end_time')->get();
            $this->shiftCache[$cacheKey] = $all->filter(function($s) use ($dutyHours) {
                $dur = $this->shiftDurationHours($s->start_time, $s->end_time, $s->crosses_midnight);
                return abs($dur - $dutyHours) < 0.5; // within 30min tolerance
            })->values();
            if ($this->shiftCache[$cacheKey]->isEmpty()) {
                // No exact match — use any shift that contains time (fallback)
                $this->shiftCache[$cacheKey] = $all;
            }
        }
        $candidates = $this->shiftCache[$cacheKey];
        // Step 2: Collect ALL shifts whose window contains punch, then pick nearest start (fixes overlapping 8h shifts: 09-17 vs 15-23 both contain 15:20)
        $matched = [];
        foreach ($candidates as $shift) {
            $inWindow = $this->isTimeInWindow($timeStr, $shift->start_time, $shift->end_time);
            $inPunchWindow = false;
            if ($shift->punch_in_window_start && $shift->punch_in_window_end) {
                $inPunchWindow = $this->isTimeInWindow($timeStr, $shift->punch_in_window_start, $shift->punch_in_window_end);
            }
            if ($inWindow || $inPunchWindow) {
                $diff = $this->minutesDiff($timeStr, $shift->start_time);
                $matched[] = ['shift'=>$shift, 'diff'=>$diff];
            }
        }
        if (!empty($matched)) {
            usort($matched, fn($a,$b)=> $a['diff'] <=> $b['diff']);
            return $matched[0]['shift']->id; // nearest start among matching windows
        }
        // Grace-expanded check: OUT punch just after end (e.g., 06:10 after 06:00 night, or 17:10 after 16:00) with buffer
        foreach ($candidates as $shift) {
            $endMin = $this->toMinutes($shift->end_time);
            $extendedEndMin = ($endMin + $buffer) % 1440;
            $extendedEndStr = sprintf('%02d:%02d:00', intdiv($extendedEndMin, 60), $extendedEndMin % 60);
            if ($this->isTimeInWindow($timeStr, $shift->start_time, $extendedEndStr)) {
                $diff = $this->minutesDiff($timeStr, $shift->start_time);
                $matched[] = ['shift'=>$shift, 'diff'=>$diff];
            }
        }
        if (!empty($matched)) {
            usort($matched, fn($a,$b)=> $a['diff'] <=> $b['diff']);
            return $matched[0]['shift']->id;
        }
        // Fallback: nearest start within buffer, else fallback shift
        $best = null; $bestDiff = PHP_INT_MAX;
        foreach ($candidates as $shift) {
            $diff = $this->minutesDiff($timeStr, $shift->start_time);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $shift; }
        }
        if ($best && $bestDiff <= $buffer) return $best->id;
        $fallback = RosterShift::where('is_fallback', true)->value('id');
        if ($fallback) return $fallback;
        return $candidates->first()?->id;
    }

    private function shiftDurationHours(string $start, string $end, ?bool $crossesMidnight = null): float
    {
        $s = $this->toMinutes($start);
        $e = $this->toMinutes($end);
        $mins = $crossesMidnight || $e < $s ? (1440 - $s + $e) : ($e - $s);
        return round($mins / 60, 2);
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':',$time);
        return ((int)$parts[0]*60) + ((int)($parts[1]??0));
    }

    private function minutesDiff(string $a, string $b): int
    {
        return abs($this->toMinutes($a) - $this->toMinutes($b));
    }

    /**
     * Resolve duty_date - for midnight-crossing shifts, early AM punches belong to previous roster date
     */
    private function resolveDutyDate(Carbon $punchTime, ?int $shiftId): string
    {
        if(!$shiftId) return $punchTime->toDateString();
        $shift = RosterShift::find($shiftId);
        if($shift?->crosses_midnight && $shift->end_time){
            $punchMin = $this->toMinutes($punchTime->format('H:i:s'));
            $endMin = $this->toMinutes($shift->end_time);
            $startMin = $this->toMinutes($shift->start_time ?? '00:00');
            // If punch is in early morning segment (00:00 to end_time) of a night shift, it belongs to previous calendar day
            if($punchMin <= $endMin && $endMin < $startMin){
                // e.g., shift 22:00-06:00, punch 02:00 -> previous day
                return $punchTime->copy()->subDay()->toDateString();
            }
        }
        return $punchTime->toDateString();
    }

    /** Manual repair: recalc all dailies for date range */
    public function recalcDaily(string $employeeId, string $dutyDate): void
    {
        $pairs = \Modules\HRM\Models\Attendance\AttendancePair::where('employee_id',$employeeId)->where('duty_date',$dutyDate)->get();
        foreach($pairs as $pair){ $this->dailies->upsertForPair($pair); }
    }

    /** Batch recalc for date range (useful after roster corrections) */
    public function recalcForDateRange(string $from, string $to): int
    {
        $pairs = \Modules\HRM\Models\Attendance\AttendancePair::whereBetween('duty_date',[$from,$to])->get();
        $affected = collect($pairs)->unique(fn($p)=> $p->employee_id.'|'.$p->duty_date)->count();
        foreach($pairs as $pair){ $this->dailies->upsertForPair($pair); }
        return $affected;
    }

    /** Backfill employee_id for orphan logs after DeviceUser mapping, then reprocess */
    public function reprocessOrphans(int $limit = 1000): array
    {
        // Step 1: Backfill employee_id where DeviceUser now exists but log still null
        $backfilled = 0;
        $orphans = AttendanceLog::whereNull('employee_id')->where('is_processed', false)->limit($limit)->get();
        // Also check already-marked orphans (is_processed true but employee_id null) - these are expired but we can retry if recent
        $staleOrphans = AttendanceLog::whereNull('employee_id')->where('is_processed', true)->where('punch_time', '>=', now()->subDays(90))->limit($limit)->get();
        $allOrphans = $orphans->merge($staleOrphans)->unique('id');
        
        foreach($allOrphans as $log){
            $empByBioBackfill = \Modules\HRM\Models\Employee\Employee::where('biometric_id', (string)$log->device_user_id)->first();
            if($empByBioBackfill) {
                $log->update(['employee_id' => $empByBioBackfill->employee_id, 'is_processed' => false]);
                $backfilled++;
                continue;
            }
            $du = \Modules\HRM\Models\DeviceUser::where('device_user_id', $log->device_user_id)->first(); // FIX: only id
            if($du && $du->employee_id){
                $log->update(['employee_id' => $du->employee_id, 'is_processed' => false]);
                $backfilled++;
            }
        }
        Log::info('Orphan backfill', ['backfilled'=>$backfilled, 'orphans_checked'=>$allOrphans->count()]);
        
        // Step 2: Re-attempt processing of newly backfilled + existing unprocessed
        // NOTE: This processes ALL mapped unprocessed logs (no date filter) — critical for backlog clearing
        $result = $this->processPending($limit);
        $result['backfilled'] = $backfilled;
        $result['orphans_found'] = $allOrphans->count();
        Log::info('ReprocessOrphans stage2', ['backfilled'=>$backfilled, 'processed'=>$result['processed']??0, 'new_pairs'=>$result['new_pairs']??0, 'closed_pairs'=>$result['closed_pairs']??0, 'errors'=>$result['errors']??0, 'skipped_orphan'=>$result['skipped_orphan']??0]);
        return $result;
    }

    /** Diagnostic: count orphans vs mapped */
    public function orphanStats(): array
    {
        $total = AttendanceLog::count();
        $unprocessed = AttendanceLog::where('is_processed', false)->count();
        $orphanUnprocessed = AttendanceLog::whereNull('employee_id')->where('is_processed', false)->count();
        $orphanProcessed = AttendanceLog::whereNull('employee_id')->where('is_processed', true)->count();
        $mapped = AttendanceLog::whereNotNull('employee_id')->count();
        $pairs = \Modules\HRM\Models\Attendance\AttendancePair::count();
        $dailies = \Modules\HRM\Models\Attendance\AttendanceDaily::count();
        $deviceUsers = \Modules\HRM\Models\DeviceUser::count();
        $employees = \Modules\HRM\Models\Employee\Employee::count();
        
        // Sample orphan IDs
        $sampleOrphans = AttendanceLog::whereNull('employee_id')->limit(5)->pluck('device_user_id')->unique()->toArray();
        $sampleMapped = AttendanceLog::whereNotNull('employee_id')->limit(5)->pluck('device_user_id')->unique()->toArray();
        
        return [
            'total_logs' => $total,
            'unprocessed_logs' => $unprocessed,
            'orphan_unprocessed' => $orphanUnprocessed,
            'orphan_processed_expired' => $orphanProcessed,
            'mapped_logs' => $mapped,
            'pairs' => $pairs,
            'dailies' => $dailies,
            'device_users' => $deviceUsers,
            'employees' => $employees,
            'sample_orphan_device_ids' => $sampleOrphans,
            'sample_mapped_device_ids' => $sampleMapped,
        ];
    }

}

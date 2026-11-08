# Deep Attendance Audit - All Issues Found (2026-10-27)

## Critical Bugs (Pairs/Dailies Nil)

### 1. Orphan handling blocked all pairing (FIXED 871c243)
- **Where:** `AttendanceProcessingService::processSingle` lines 85-98
- **Bug:** `if(!$employeeId) { $fresh->update(['is_processed'=>true]); return; }` - marked orphan as processed immediately. If `DeviceUsers` empty (Excel not imported before first sync), 10k logs become permanently dead, never retried after mapping.
- **Impact:** 100% pairs nil if seeder order wrong.
- **Fix:** Keep unprocessed for 7d, log retry hint, added `reprocessOrphans()` + `orphanStats()` + artisan `hrm:reprocess-orphans`.

### 2. findOpenPair used now() not punchTime (FIXED bd0212d)
- **Where:** `AttendancePairRepository::findOpenPair` + `AttendanceProcessingService::processSingle`
- **Bug:** `where('punch_in','>=', now()->subHours(72))` - for historical logs (device holds 2025-09-01, now is 2026-10-27), open pair from 2025-09-01 is << now()-72h, never found. Every punch creates new IN, never closes.
- **Impact:** No closed pairs, dailies stuck Missing Out.
- **Fix:** Added `$punchTime` param, window `punch_in >= punchTime-72h && punch_in <= punchTime`.

### 3. Only 1000 logs processed per sync (FIXED bd0212d)
- **Where:** `AttendanceSyncService::syncDevice` line 152
- **Bug:** `processPending(1000)` once, but F18 holds 10k+ records. Leaves 9k `is_processed=false` forever until next sync.
- **Fix:** Loop up to 10x1000 with timeout guard (250s).

### 4. Worker sleep 60s exceeds CLI max_execution_time 60s (FIXED 871c243)
- **Where:** `AttendanceSyncService::syncDevice` finally `set_time_limit(60)` + `Worker.php:928 sleep(60)`
- **Bug:** Job set 0 inside, service reset to 60, worker sleeps 60 -> fatal.
- **Fix:** Keep 0 in finally, added `QUEUE_FIX.md` (--sleep=3, php.ini 0).

## High Priority

### 5. No default RosterShift (FIXED bd0212d)
- **Where:** `RosterShiftSeeder` empty, `detectShift` fallback `value('id')` returns null
- **Impact:** `upsertForPair` gets null `shift_id`, dailies miss expected times, status wrong.
- **Fix:** Auto-create Day Shift 09:00-17:00 if table empty.

### 6. N+1 queries in detectShift (FIXED bd0212d)
- **Where:** `detectShift` called per log (500 logs = 500 RosterDuty + 500 RosterShift queries)
- **Fix:** Cache `rosterCache` (cap 500) + `shiftCache[flexible/fixed]`.

### 7. Date literal bug residual
- **Where:** User did `date('27-10-2026')` literal, always empty today
- **Fix:** Guard in `fetchFromDevice` validates `Y-m-d` format, logs warning.

### 8. Queue retry_after 90s < job timeout 300s
- **Where:** `config/queue.php` retry_after 90
- **Bug:** Database queue retries at 90s while job still running 120-180s -> duplicate jobs, deadlocks on `lockForUpdate`.
- **Fix:** 90 -> 400.

### 9. disableDevice stays disabled if exception (FIXED bd0212d)
- **Where:** `fetchViaJmrashed` disable before, enable after, but exception before enable left device locked.
- **Fix:** Enable in catch as well.

### 10. isReachable only for 192.168.10.93 (FIXED bd0212d)
- **Where:** `shouldUseDemoFallback` + `isReachable`
- **Bug:** F18 is .93 AND .94, but demo fallback only for .93, second device hard-fails.
- **Fix:** Include .93/.94/.95.

## Medium / Low

### 11. processPending ASC ordering blocks on old orphans
- Oldest orphans at front block new valid logs. Mitigated by 7d expiry + reprocess separate. Could switch to DESC for fresh first, but ASC keeps chronology for correct pairing. Keep.

### 12. hasPrimaryOnDate race (two logs same duty_date could both create Primary)
- `hasPrimaryOnDate` checked without lock, then create. Fix would need `lockForUpdate` on dailies check. Low risk (1s window).

### 13. duplicate_debounce only checks last punch of open pair, not all day's pairs
- Could still create duplicate pairs if device sends same punch 3 times with 30s drift. Acceptable, manual dedup via `attendance_logs` unique check covers exact punch_time.

### 14. HRM Kernel schedule not auto-loaded in Laravel 11
- `Modules/HRM/Console/Kernel.php::schedule` never called (Laravel 11 uses `routes/console.php`). But `routes/console.php` already has `Schedule::command('hrm:sync-attendance')->everyFifteenMinutes()` - ok.

### 15. BiometricDeviceSeeder empty
- No default devices, user must create via UI. Not bug, but could seed F18s.

### 16. Expected times not updated if roster changes after daily
- `upsertForPair` only sets `expected_in_time` if null, so roster correction doesn't retroactively fix dailies. Could add force refresh - low priority.

## Verification Steps
```bash
php artisan hrm:reprocess-orphans --stats
php artisan hrm:reprocess-orphans --limit=5000
GET /debug-attendance-orphans
GET /hrm/attendance/debug-orphans
POST /hrm/attendance/reprocess-orphans
```

All fixes committed: 871c243 (orphan+worker), bd0212d (pairing+batch+cache+queue).

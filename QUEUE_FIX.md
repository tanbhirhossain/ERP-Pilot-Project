# Queue Worker Timeout Fix

Your error:
```
Maximum execution time of 60 seconds exceeded in Worker.php:928 sleep(60s)
```

Root cause: PHP CLI `max_execution_time=60` kills the worker during its 60s sleep between polls, even though job had `timeout 300` and `set_time_limit(0)` inside job.

Fixed: `AttendanceSyncService` now keeps `@set_time_limit(0)` in finally (was 60), so worker not reset.

But you ALSO must fix PHP config:

## Option 1: Edit php.ini (recommended, permanent)
Find your php.ini (run `php --ini`):
```
max_execution_time = 0
```
Restart terminal.

## Option 2: Use --sleep=3 (quick workaround)
Instead of default 60s sleep, use 3s:
```
php -d max_execution_time=0 artisan queue:work --timeout=300 --sleep=3 --tries=1
```
Or use supervisor with:
```
php artisan queue:listen --timeout=300 --sleep=3
```

## Option 3: Use sync directly (no queue needed)
In HRM → Attendance Sync, uncheck queue or run:
```
php artisan hrm:sync-attendance --device=1 --since=today
php artisan hrm:reprocess-orphans
```

## Verify pairs after fix
```
GET /debug-attendance-orphans  (JSON diagnosis)
POST /hrm/attendance/reprocess-orphans  (backfill orphans)
php artisan hrm:reprocess-orphans --stats
php artisan hrm:reprocess-orphans --limit=5000
```

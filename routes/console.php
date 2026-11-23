<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One shared lock prevents the scheduled raw sync and pipeline from using devices concurrently.
// Put the full pipeline first when both are due; preserve the existing 15-minute incremental sync.
Schedule::command('hrm:pipeline --from=yesterday --to=today')
    ->cron('0 9,14,22 * * *')->timezone('Asia/Dhaka')
    ->createMutexNameUsing('hrm-attendance-scheduled-device-sync')->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/hrm-pipeline-scheduled.log'));
Schedule::command('hrm:sync-attendance')->everyFifteenMinutes()
    ->createMutexNameUsing('hrm-attendance-scheduled-device-sync')->withoutOverlapping(180);

// ------------------------------------------------ Phase 2 (F21) business jobs
// Each command is idempotent (daily cache lock / firstOrCreate) and logs its
// run, so an overlapping or repeated scheduler tick is harmless.
Schedule::command('erp:low-stock-alerts')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('erp:overdue-invoice-reminders')->dailyAt('08:15')->withoutOverlapping();
Schedule::command('erp:credit-limit-review')->dailyAt('08:30')->withoutOverlapping();
// Accrue on the 1st of each month at 01:00 — firstOrCreate makes re-runs no-ops.
Schedule::command('erp:leave-balance-accrual')->monthlyOn(1, '01:00')->withoutOverlapping();
// Depreciate last month on the 1st at 02:00, after the accrual — an already-run period is a
// no-op success, so a repeated tick never double-posts.
Schedule::command('erp:depreciation-run')->monthlyOn(1, '02:00')->timezone('Asia/Dhaka')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/depreciation-scheduled.log'));

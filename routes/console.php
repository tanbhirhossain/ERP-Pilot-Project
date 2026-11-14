<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HRM Automated Attendance Sync every 15 minutes
Schedule::command('hrm:sync-attendance')->everyFifteenMinutes()->withoutOverlapping();

// ------------------------------------------------ Phase 2 (F21) business jobs
// Each command is idempotent (daily cache lock / firstOrCreate) and logs its
// run, so an overlapping or repeated scheduler tick is harmless.
Schedule::command('erp:low-stock-alerts')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('erp:overdue-invoice-reminders')->dailyAt('08:15')->withoutOverlapping();
Schedule::command('erp:credit-limit-review')->dailyAt('08:30')->withoutOverlapping();
// Accrue on the 1st of each month at 01:00 — firstOrCreate makes re-runs no-ops.
Schedule::command('erp:leave-balance-accrual')->monthlyOn(1, '01:00')->withoutOverlapping();

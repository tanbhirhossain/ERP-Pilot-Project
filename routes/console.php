<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HRM Automated Attendance Sync every 15 minutes
Schedule::command('hrm:sync-attendance')->everyFifteenMinutes()->withoutOverlapping();

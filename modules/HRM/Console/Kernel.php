<?php
namespace Modules\HRM\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\App;
class Kernel {
    public function schedule(Schedule $schedule): void {
        // Auto sync every 15 min for devices with auto_sync_enabled
        $schedule->command('hrm:sync-attendance')->everyFifteenMinutes()->withoutOverlapping();
    }
}

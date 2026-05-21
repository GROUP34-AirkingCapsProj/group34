<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Auto-archive: runs daily at 02:00 AM
        // Time and frequency are configurable in Archive Settings UI
        $schedule->command('archive:auto')
                 ->dailyAt('02:00')
                 ->runInBackground()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/archive-auto.log'));

        // Retention cleanup: runs weekly on Sunday at 03:00 AM
        $schedule->command('archive:cleanup')
                 ->weeklyOn(0, '03:00')
                 ->runInBackground()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/archive-cleanup.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}

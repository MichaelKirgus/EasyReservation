<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('scheduled-tasks:run')->everyMinute();
        // CheckScheduledTasksJob alle 30 Sekunden ausführen (Laravel 10+)
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyThirtySeconds();
        // Worker Heartbeat alle 10 Sekunden
        $schedule->command('worker:heartbeat')->everyTenSeconds();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        \Log::info('Starting overdue tasks...');
        $schedule->command('scheduled-tasks:run')->everyMinute();
        // CheckScheduledTasksJob alle 30 Sekunden ausführen (Laravel 10+)
        \Log::info('Register scheduled task job...');
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyThirtySeconds();
        // Worker HeartbeatJob alle 30 Sekunden mit niedriger Prio-Queue
        \Log::info('Register heartbeat worker job...');
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyThirtySeconds()->onQueue('heartbeat');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

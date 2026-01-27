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
        \Log::info('Register scheduled task job...');
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyMinute();
        \Log::info('Register heartbeat worker job...');
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyMinute()->onQueue('heartbeat');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

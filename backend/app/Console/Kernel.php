<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        \Log::info('Registering scheduled tasks...');
        
        // Run the command-based scheduler every minute
        $schedule->command('scheduled-tasks:run')->everyMinute()->withoutOverlapping();
        \Log::info('Registered scheduled-tasks:run command');
        
        // Run the job-based scheduler every minute (alternative approach)
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyMinute()->withoutOverlapping();
        \Log::info('Registered CheckScheduledTasksJob');
        
        // Register heartbeat worker job
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyMinute()->onQueue('heartbeat');
        \Log::info('Registered WorkerHeartbeatJob on heartbeat queue');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

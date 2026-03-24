<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        \Log::info('Registering scheduled tasks...');

        // Run scheduled task checks via one single path to avoid divergent behavior.
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyMinute()->withoutOverlapping();
        \Log::info('Registered CheckScheduledTasksJob');
        
        // Register heartbeat worker job
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyMinute()->onQueue('heartbeat');
        \Log::info('Registered WorkerHeartbeatJob on heartbeat queue');

        // Schedule CleanUpJobLogsJob to run hourly
        $schedule->job(new \App\Jobs\CleanUpJobLogsJob)->hourly();
        \Log::info('Registered CleanUpJobLogsJob for hourly cleanup');

        // Schedule CleanUpAuditLogsJob to run hourly
        $schedule->job(new \App\Jobs\CleanUpAuditLogsJob)->hourly();
        \Log::info('Registered CleanUpAuditLogsJob for hourly cleanup');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

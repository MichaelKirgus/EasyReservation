<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScheduledTaskService;

class RunScheduledTasks extends Command
{
    protected $signature = 'scheduled-tasks:run';
    protected $description = 'Führt fällige geplante Aufgaben aus';

    public function handle(ScheduledTaskService $service)
    {
        \Log::info('RunScheduledTasks: Starting execution via ScheduledTaskService');

        $service->runDueTasks();

        \Log::info('RunScheduledTasks: Execution completed');
        return 0;
    }
}

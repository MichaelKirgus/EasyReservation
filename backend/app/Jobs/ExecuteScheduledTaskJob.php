<?php
namespace App\Jobs;

use App\Models\ScheduledTask;
use App\Services\ScheduledTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $taskId;

    public function __construct($taskId)
    {
        $this->taskId = $taskId;
    }

    public function handle()
    {
        $task = ScheduledTask::find($this->taskId);
        if (!$task) return;
        // Die Ausführung erfolgt wie im Service
        $service = app(ScheduledTaskService::class);
        $service->executeTask($task);
    }
}

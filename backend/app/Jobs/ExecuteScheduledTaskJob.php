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
        \Log::info('ExecuteScheduledTaskJob: Starting job for task ID ' . $this->taskId);
        
        try {
            $task = ScheduledTask::find($this->taskId);
            
            if (!$task) {
                \Log::error('ExecuteScheduledTaskJob: Task with ID ' . $this->taskId . ' not found');
                return;
            }
            
            \Log::info('ExecuteScheduledTaskJob: Found task ' . $task->id . ', executing via ScheduledTaskService');
            
            // Die Ausführung erfolgt wie im Service
            $service = app(ScheduledTaskService::class);
            $service->executeTask($task);
            
            \Log::info('ExecuteScheduledTaskJob: Task ' . $this->taskId . ' completed successfully');
        } catch (\Throwable $e) {
            \Log::error('ExecuteScheduledTaskJob: Error executing task ' . $this->taskId . ': ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}

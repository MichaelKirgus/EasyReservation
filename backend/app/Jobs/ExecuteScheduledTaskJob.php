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
    public bool $manualRun;

    public function __construct($taskId, bool $manualRun = false)
    {
        $this->taskId = $taskId;
        $this->manualRun = $manualRun;
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
            
            \Log::info('ExecuteScheduledTaskJob: Found task ' . $task->id . ', executing via ScheduledTaskService (manualRun=' . ($this->manualRun ? 'true' : 'false') . ')');
            
            // Die Ausführung erfolgt wie im Service
            $service = app(ScheduledTaskService::class);

            // Manual "Run now" should not consume a scheduled run, unless run_once is enabled.
            $finalizeExecution = !$this->manualRun || (bool) $task->run_once;
            $service->executeTask($task, $finalizeExecution, $this->manualRun ? 'manual' : 'scheduler');
            
            \Log::info('ExecuteScheduledTaskJob: Task ' . $this->taskId . ' completed successfully');
        } catch (\Throwable $e) {
            \Log::error('ExecuteScheduledTaskJob: Error executing task ' . $this->taskId . ': ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}

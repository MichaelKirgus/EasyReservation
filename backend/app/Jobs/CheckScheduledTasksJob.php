<?php
namespace App\Jobs;

use App\Services\ScheduledTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckScheduledTasksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Bound the unique-job lock so a killed worker (restart/OOM) can't leave it
    // stuck forever, which would silently stop every future dispatch of this job.
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return static::class;
    }

    public function handle(ScheduledTaskService $service): void
    {
        \Log::info('CheckScheduledTasksJob: Starting check for due tasks');
        
        try {
            // Fällige Aufgaben prüfen und ausführen (axiom/relativ/absolut)
            $service->runDueTasks();
            
            \Log::info('CheckScheduledTasksJob: Task check completed successfully');
        } catch (\Throwable $e) {
            \Log::error('CheckScheduledTasksJob: Error during task check: ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}

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

    public function handle(ScheduledTaskService $service): void
    {
        // Fällige Aufgaben prüfen und ausführen (axiom/relativ/absolut)
        $service->runDueTasks();
    }
}

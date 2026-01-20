<?php
namespace App\Services;

use App\Models\ScheduledTask;
use App\Models\Event;
use Illuminate\Support\Carbon;

class ScheduledTaskService
{
    public function runDueTasks(): void
    {
        // 1. Relative Zeiten berechnen (wie in RunScheduledTasks)
        $tasks = ScheduledTask::where('active', true)->where('executed', false)->get();
        foreach ($tasks as $task) {
            if ($task->relative_to && $task->reference_type === 'event') {
                $event = null;
                if ($task->reference_id) {
                    $event = Event::find($task->reference_id);
                } else {
                    $event = Event::where('start_at', '>=', now())->orderBy('start_at')->first();
                }
                if ($event && $event->{$task->relative_to}) {
                    $calculated = $event->{$task->relative_to}->copy()->addMinutes($task->relative_offset_minutes);
                    if (!$task->run_at || !$task->run_at->eq($calculated)) {
                        $task->run_at = $calculated;
                        $task->save();
                    }
                }
            }
        }

        // 2. Fällige Tasks ausführen
        $dueTasks = ScheduledTask::where('run_at', '<=', now())
            ->where('executed', false)
            ->where('active', true)
            ->orderBy('run_at')
            ->get();

        foreach ($dueTasks as $task) {
            // Hier: Eigentliche Ausführung der Aufgabe (z.B. Mail, Webhook, ...)
            // $this->executeTask($task);
            $task->executed = true;
            $task->executed_at = now();
            $task->save();
        }
    }
}

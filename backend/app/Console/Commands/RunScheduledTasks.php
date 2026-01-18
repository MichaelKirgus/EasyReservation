<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledTask;
use Illuminate\Support\Facades\Log;
use App\Services\EmailBroadcastService;
use App\Services\EventService;

class RunScheduledTasks extends Command
{
    protected $signature = 'scheduled-tasks:run';
    protected $description = 'Führt fällige geplante Aufgaben aus';

    public function handle()
    {
        $tasks = ScheduledTask::where('executed', false)->get();

        foreach ($tasks as $task) {
            // Dynamische Berechnung von run_at, falls relative Felder gesetzt sind
            if ($task->reference_type === 'event' && $task->reference_id && $task->relative_to && $task->relative_offset_minutes !== null) {
                $event = \App\Models\Event::find($task->reference_id);
                if ($event && $event->{$task->relative_to}) {
                    $calculated = $event->{$task->relative_to}->copy()->addMinutes($task->relative_offset_minutes);
                    // Nur aktualisieren, wenn run_at unterschiedlich ist
                    if (!$task->run_at || !$task->run_at->eq($calculated)) {
                        $task->run_at = $calculated;
                        $task->save();
                    }
                }
            }
        }

        // Nach Aktualisierung: nur fällige Tasks ausführen
        $dueTasks = ScheduledTask::where('run_at', '<=', now())
            ->where('executed', false)
            ->orderBy('run_at')
            ->get();

        foreach ($dueTasks as $task) {
            try {
                // Wenn reference_id null: für alle zukünftigen Events ausführen
                if ($task->reference_type === 'event' && ($task->reference_id === null || $task->reference_id === 0)) {
                    $futureEvents = \App\Models\Event::where('start_at', '>=', now())->get();
                    foreach ($futureEvents as $event) {
                        switch ($task->type) {
                            case 'email_broadcast':
                                $service = app(EmailBroadcastService::class);
                                $service->sendTemplateToReservationList($event->id, $task->options['template_id'] ?? null);
                                break;
                            case 'close_reservation':
                                $service = app(EventService::class);
                                $service->closeReservationList($event->id);
                                break;
                            default:
                                Log::warning('Unbekannter Task-Typ: ' . $task->type);
                        }
                    }
                    $task->executed = true;
                    $task->save();
                    continue;
                }
                // Standard: nur für das eine Event
                switch ($task->type) {
                    case 'email_broadcast':
                        $service = app(EmailBroadcastService::class);
                        $service->sendTemplateToReservationList($task->options['event_id'] ?? $task->reference_id, $task->options['template_id'] ?? null);
                        break;
                    case 'close_reservation':
                        $service = app(EventService::class);
                        $service->closeReservationList($task->options['event_id'] ?? $task->reference_id);
                        break;
                    default:
                        Log::warning('Unbekannter Task-Typ: ' . $task->type);
                }
                $task->executed = true;
                $task->save();
            } catch (\Throwable $e) {
                Log::error('Fehler beim Ausführen von ScheduledTask ' . $task->id . ': ' . $e->getMessage());
            }
        }
        return 0;
    }
}

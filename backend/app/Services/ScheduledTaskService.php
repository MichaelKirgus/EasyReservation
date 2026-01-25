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
            $this->executeTask($task);
        }
        // Letzte Ausführung im Cache speichern
        \Cache::put('scheduler:last_executed_at', now(), 86400);
    }

    /**
     * Führt eine einzelne geplante Aufgabe aus (Logik wie im Command)
     */
    public function executeTask(ScheduledTask $task): void
    {
        try {
            switch ($task->type) {
                case 'attendees_email_broadcast': {
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $service->sendTemplateToReservationList($task->options['event_id'] ?? $task->reference_id, $task->options['template_id'] ?? null);
                    break;
                }
                case 'waitlist_email_broadcast': {
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $service->queueBroadcast(
                        $task->options['template_id'] ?? null,
                        'waitlist',
                        true,
                        [],
                        [],
                        [],
                        true
                    );
                    break;
                }
                case 'custom_email_broadcast': {
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $customRecipients = $task->options['custom_recipients'] ?? [];
                    $service->queueBroadcast(
                        $task->options['template_id'] ?? null,
                        'selection',
                        false,
                        [],
                        [],
                        $customRecipients,
                        true
                    );
                    break;
                }
                case 'change_setting':
                    $key = $task->options['setting_key'] ?? null;
                    $value = $task->options['setting_value'] ?? null;
                    if ($key !== null) {
                        \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                    }
                    break;
                case 'webhook':
                    $webhookService = app(\App\Services\WebhookService::class);
                    $webhookId = $task->options['webhook_template_id'] ?? null;
                    $payload = $task->options['payload'] ?? [];
                    if ($webhookId) {
                        $webhookService->sendTemplate($webhookId, $payload);
                    } else {
                        \Log::warning('Webhook-Task ohne webhook_template_id: ' . $task->id);
                    }
                    break;
                // Weitere Typen hier ergänzen
                default:
                    // Unbekannter Task-Typ
                    \Log::warning('Unbekannter Task-Typ: ' . $task->type);
            }
            $task->executed = true;
            $task->executed_at = now();
            $task->save();
        } catch (\Throwable $e) {
            \Log::error('Fehler beim Ausführen von ScheduledTask ' . $task->id . ': ' . $e->getMessage());
            throw $e;
        }
    }
}

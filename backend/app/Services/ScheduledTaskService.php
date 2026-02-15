<?php
namespace App\Services;

use App\Models\ScheduledTask;
use App\Models\Event;
use Illuminate\Support\Carbon;
use App\Jobs\ExecuteScheduledTaskJob;

class ScheduledTaskService
{
    public function runDueTasks(): void
    {
        \Log::info('ScheduledTaskService: Starting runDueTasks');
        
        // 1. Relative Zeiten berechnen (wie in RunScheduledTasks)
        $tasks = ScheduledTask::where('active', true)->where('executed', false)->get();
        \Log::info('ScheduledTaskService: Found ' . $tasks->count() . ' active, unexecuted tasks');
        
        foreach ($tasks as $task) {
            if ($task->relative_to && $task->reference_type === 'event') {
                $event = null;
                if ($task->reference_id) {
                    $event = Event::find($task->reference_id);
                } else {
                    $event = Event::where('start_at', '>=', now())->orderBy('start_at')->first();
                }
                
                \Log::debug('ScheduledTaskService: Checking relative time for task ' . $task->id, [
                    'reference_type' => $task->reference_type,
                    'reference_id' => $task->reference_id,
                    'relative_to' => $task->relative_to,
                    'relative_offset_minutes' => $task->relative_offset_minutes
                ]);
                
                if ($event && $event->{$task->relative_to}) {
                    $calculated = $event->{$task->relative_to}->copy()->addMinutes($task->relative_offset_minutes);
                    \Log::debug('ScheduledTaskService: Calculated run_at for task ' . $task->id . ': ' . $calculated->toIso8601String());
                    
                    if (!$task->run_at || !$task->run_at->eq($calculated)) {
                        $task->run_at = $calculated;
                        $task->save();
                        \Log::info('ScheduledTaskService: Updated run_at for task ' . $task->id);
                    }
                } else {
                    \Log::debug('ScheduledTaskService: No event found or relative_to field missing for task ' . $task->id);
                }
            }
        }

        // 2. Fällige Tasks ausführen
        $dueTasks = ScheduledTask::where('run_at', '<=', now())
            ->where('executed', false)
            ->where('active', true)
            ->orderBy('run_at')
            ->get();

        \Log::info('ScheduledTaskService: Found ' . $dueTasks->count() . ' due tasks to execute');
        
        foreach ($dueTasks as $task) {
            \Log::info('ScheduledTaskService: Executing task ' . $task->id);
            try {
                $this->executeTask($task);
                \Log::info('ScheduledTaskService: Task ' . $task->id . ' executed successfully');
            } catch (\Throwable $e) {
                \Log::error('ScheduledTaskService: Failed to execute task ' . $task->id . ': ' . $e->getMessage());
            }
        }
        
        // Letzte Ausführung IMMER im Cache speichern, auch wenn keine Tasks fällig waren
        \Cache::put('scheduler:last_executed_at', now(), 86400);
        \Log::info('ScheduledTaskService: runDueTasks completed');
    }

    /**
     * Führt eine einzelne geplante Aufgabe aus (Logik wie im Command)
     */
    public function executeTask(ScheduledTask $task): void
    {
        \Log::info('ScheduledTaskService: Starting execution of task ' . $task->id . ' (type: ' . $task->type . ')');
        
        try {
            // Log task details for debugging
            \Log::debug('ScheduledTask details:', [
                'id' => $task->id,
                'type' => $task->type,
                'options' => $task->options,
                'reference_type' => $task->reference_type,
                'reference_id' => $task->reference_id,
                'run_at' => $task->run_at?->toIso8601String(),
            ]);
            
            switch ($task->type) {
                case 'attendees_email_broadcast': {
                    \Log::info('Executing attendees_email_broadcast for task ' . $task->id);
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $eventId = $task->options['event_id'] ?? $task->reference_id;
                    $templateId = $task->options['template_id'] ?? null;
                    \Log::debug('attendees_email_broadcast params:', ['event_id' => $eventId, 'template_id' => $templateId]);
                    $service->sendTemplateToReservationList($eventId, $templateId);
                    break;
                }
                case 'waitlist_email_broadcast': {
                    \Log::info('Executing waitlist_email_broadcast for task ' . $task->id);
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $templateId = $task->options['template_id'] ?? null;
                    \Log::debug('waitlist_email_broadcast params:', ['template_id' => $templateId]);
                    $service->queueBroadcast(
                        $templateId,
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
                    \Log::info('Executing custom_email_broadcast for task ' . $task->id);
                    $service = app(\App\Services\EmailBroadcastService::class);
                    $customRecipients = $task->options['custom_recipients'] ?? [];
                    $templateId = $task->options['template_id'] ?? null;
                    \Log::debug('custom_email_broadcast params:', [
                        'template_id' => $templateId,
                        'custom_recipients_count' => count($customRecipients)
                    ]);
                    $service->queueBroadcast(
                        $templateId,
                        'selection',
                        false,
                        [],
                        [],
                        $customRecipients,
                        true
                    );
                    break;
                }
                case 'change_setting': {
                    \Log::info('Executing change_setting for task ' . $task->id);
                    $key = $task->options['setting_key'] ?? null;
                    $value = $task->options['setting_value'] ?? null;
                    \Log::debug('change_setting params:', ['key' => $key, 'value' => $value]);
                    if ($key !== null) {
                        \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                        \Log::info('Setting updated successfully: ' . $key);
                    } else {
                        \Log::warning('change_setting called without setting_key for task ' . $task->id);
                    }
                    break;
                }
                case 'webhook': {
                    \Log::info('Executing webhook for task ' . $task->id);
                    $webhookService = app(\App\Services\WebhookService::class);
                    $webhookId = $task->options['webhook_template_id'] ?? null;
                    $payload = $task->options['payload'] ?? [];
                    
                    if (!$webhookId) {
                        \Log::warning('Webhook-Task ohne webhook_template_id: ' . $task->id);
                        throw new \InvalidArgumentException('No webhook_template_id provided for task ' . $task->id);
                    }
                    
                    \Log::debug('webhook params:', [
                        'webhook_template_id' => $webhookId,
                        'payload' => $payload
                    ]);
                    
                    // Get template to log URL before sending
                    try {
                        $template = \App\Models\WebhookTemplate::find($webhookId);
                        if ($template) {
                            \Log::info('Webhook: Sending to URL ' . $template->url);
                        } else {
                            \Log::warning('Webhook-Template not found for ID: ' . $webhookId);
                            throw new \RuntimeException('Webhook template with ID ' . $webhookId . ' not found');
                        }
                    } catch (\Throwable $e) {
                        \Log::error('Error fetching webhook template: ' . $e->getMessage());
                        throw $e;
                    }
                    
                    try {
                        $webhookService->sendTemplate($webhookId, $payload);
                        \Log::info('Webhook executed successfully for task ' . $task->id);
                    } catch (\Throwable $e) {
                        \Log::error('Webhook execution failed for task ' . $task->id . ': ' . $e->getMessage());
                        throw $e;
                    }
                    break;
                }
                // Weitere Typen hier ergänzen
                default:
                    \Log::warning('Unbekannter Task-Typ: ' . $task->type);
                    throw new \InvalidArgumentException('Unknown task type: ' . $task->type);
            }
            
            $task->executed = true;
            $task->executed_at = now();
            $task->save();
            \Log::info('ScheduledTaskService: Task ' . $task->id . ' completed successfully');
            
        } catch (\Throwable $e) {
            \Log::error('Fehler beim Ausführen von ScheduledTask ' . $task->id . ': ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
    /**
     * Stellt die Ausführung einer geplanten Aufgabe in die Queue (für manuelle Ausführung)
     */
    public function queueTaskExecution(ScheduledTask $task): void
    {
        // Die eigentliche Ausführung erfolgt asynchron als Job, damit sie im Protokoll sichtbar ist
        \Log::info('ScheduledTaskService: queueTaskExecution für Task ' . $task->id);
        ExecuteScheduledTaskJob::dispatch($task->id);
    }
}

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
            // Handle cron-based scheduling
            if (!empty($task->cron_expression)) {
                $this->updateCronRunTime($task);
            }
            // Handle relative time scheduling (existing logic)
            elseif ($task->relative_to && $task->reference_type === 'event') {
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

        // 2. Fällige Tasks ausführen (including cron tasks)
        $dueTasks = ScheduledTask::where('active', true)
          ->where('executed', false)
          ->where(function($outer) {
              $outer->where(function($query) {
                  // Standard tasks with run_at
                  $query->where('run_at', '<=', now())
                        ->whereNull('cron_expression');
              })->orWhere(function($query) {
                  // Cron tasks where next_run_at is due
                  $query->whereNotNull('cron_expression')
                        ->where('next_run_at', '<=', now());
              });
          })
          ->orderBy('run_at')
          ->get();

        \Log::info('ScheduledTaskService: Found ' . $dueTasks->count() . ' due tasks to execute');
        
        foreach ($dueTasks as $task) {
            // Skip overdue tasks if skip_if_overdue is enabled
            if ($task->skip_if_overdue) {
                $scheduledTime = !empty($task->cron_expression) ? $task->next_run_at : $task->run_at;
                if ($scheduledTime && $scheduledTime->copy()->addMinutes(5)->lt(now())) {
                    \Log::info('ScheduledTaskService: Skipping overdue task ' . $task->id . ' (scheduled: ' . $scheduledTime->toIso8601String() . ', skip_if_overdue is enabled)');
                    // For non-cron tasks, mark as executed so they don't keep being picked up
                    if (empty($task->cron_expression)) {
                        $task->executed = true;
                        $task->executed_at = now();
                        $task->save();
                    } else {
                        // For cron tasks, advance to next run
                        $task->last_run_at = now();
                        $task->save();
                        $this->updateCronRunTime($task);
                    }
                    continue;
                }
            }

            \Log::info('ScheduledTaskService: Executing task ' . $task->id);
            try {
                $this->executeTask($task);
                
                // Update cron task for next run (cron tasks are recurring, so reset executed)
                if (!empty($task->cron_expression)) {
                    $task->last_run_at = now();
                    $task->executed = false;
                    $task->save();
                    
                    // Recalculate next_run_at after execution
                    $this->updateCronRunTime($task);
                    
                    // If run_once is enabled, deactivate the task after first cron execution
                    if ($task->run_once) {
                        $task->active = false;
                        $task->save();
                        \Log::info('ScheduledTaskService: Task ' . $task->id . ' deactivated (run_once)');
                    }
                }
                
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
     * Update the next_run_at for a cron-based task.
     */
    private function updateCronRunTime(ScheduledTask $task): void
    {
        try {
            $nextRunAt = $task->getNextRunAtAttribute();
            
            if ($nextRunAt && (!$task->next_run_at || !$task->next_run_at->eq($nextRunAt))) {
                $task->next_run_at = $nextRunAt;
                $task->save();
                \Log::info('ScheduledTaskService: Updated next_run_at for cron task ' . $task->id . ': ' . $nextRunAt->toIso8601String());
            }
        } catch (\Throwable $e) {
            \Log::error('ScheduledTaskService: Error updating cron run time for task ' . $task->id . ': ' . $e->getMessage());
        }
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
                        // Normalize value types (bool to '1'/'0', null to '', else string)
                        if (is_bool($value)) {
                            $value = $value ? '1' : '0';
                        } elseif (is_null($value)) {
                            $value = '';
                        } else {
                            $value = (string)$value;
                        }
                        // Use firstOrNew + save to trigger setValueAttribute mutator for encryption
                        $setting = \App\Models\Setting::firstOrNew(['name' => $key]);
                        $setting->value = $value;
                        $setting->save();
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
                        $template = \App\Models\WebhookTemplate::find($webhookId);
                        if ($template) {
                            $headers = [];
                            if ($template->headers_template) {
                                try {
                                    $headers = json_decode($template->headers_template, true) ?: [];
                                } catch (\Throwable $e) {
                                    $headers = [];
                                }
                            }
                            $finalPayload = !empty($payload) ? $payload : (json_decode($template->payload_template, true) ?: []);
                            \App\Jobs\SendWebhookJob::dispatch(
                                $template->url,
                                $finalPayload,
                                $headers,
                                null,
                                null,
                                $task->id
                            );
                            \Log::info('Webhook job dispatched for task ' . $task->id);
                        } else {
                            \Log::warning('Webhook-Template not found for ID: ' . $webhookId);
                            throw new \RuntimeException('Webhook template with ID ' . $webhookId . ' not found');
                        }
                    } catch (\Throwable $e) {
                        \Log::error('Webhook execution failed for task ' . $task->id . ': ' . $e->getMessage());
                        throw $e;
                    }
                    break;
                }
                // Weitere Typen hier ergänzen
                case 'survey_sendout': {
                    \Log::info('Executing survey_sendout for task ' . $task->id);
                    $surveyId = $task->options['survey_id'] ?? null;
                    $templateId = $task->options['template_id'] ?? null;
                    \Log::debug('survey_sendout params:', ['survey_id' => $surveyId, 'template_id' => $templateId]);
                    
                    if (!$surveyId) {
                        \Log::warning('Survey sendout task without survey_id: ' . $task->id);
                        throw new \InvalidArgumentException('No survey_id provided for task ' . $task->id);
                    }
                    
                    // Get the survey
                    $survey = \App\Models\Survey::find($surveyId);
                    if (!$survey) {
                        \Log::warning('Survey not found for ID: ' . $surveyId);
                        throw new \RuntimeException('Survey with ID ' . $surveyId . ' not found');
                    }
                    
                    // Get email template if provided
                    $template = null;
                    if ($templateId) {
                        $template = \App\Models\EmailTemplate::find($templateId);
                        if (!$template) {
                            \Log::warning('Email template not found for ID: ' . $templateId);
                            throw new \RuntimeException('Email template with ID ' . $templateId . ' not found');
                        }
                    }
                    
                    // Send surveys to recipients
                    $sentCount = 0;
                    $recipients = $this->getSurveyRecipients($survey);
                    
                    foreach ($recipients as $recipient) {
                        try {
                            $responseToken = $recipient['token'] ?? (string) \Illuminate\Support\Str::uuid();
                            $surveyLink = route('public.survey.response', [
                                'surveyId' => $survey->id,
                                'token' => $responseToken,
                            ]);
                            
                            // Build email body using template if provided
                            if ($template) {
                                $replacements = [
                                    '{{survey_title}}' => $survey->title,
                                    '{{survey_description}}' => $survey->description ?? '',
                                    '{{survey_link}}' => $surveyLink,
                                    '{{survey_link_html}}' => '<a href="' . $surveyLink . '">' . $surveyLink . '</a>',
                                ];
                                
                                $subject = strtr($template->subject, $replacements);
                                $body = strtr($template->body, $replacements);
                            } else {
                                // Fallback if no template selected
                                \Log::warning('Survey sendout without email template for task ' . $task->id);
                                throw new \RuntimeException('No email template selected for survey sendout');
                            }
                            
                            // Send email using existing job
                            dispatch(new \App\Jobs\SendMailJob(
                                config('mail.default'),
                                $recipient['email'] ?? '',
                                null,
                                $subject,
                                $body,
                                null,
                                null,
                                [],
                                config('mail.from.address'),
                                config('mail.from.name')
                            ));
                            
                            $sentCount++;
                        } catch (\Exception $e) {
                            \Log::error("Failed to send survey email to {$recipient['email']}: " . $e->getMessage());
                        }
                    }
                    
                    \Log::info("Survey sendout completed for task {$task->id}: {$sentCount} emails sent");
                    break;
                }
                default:
                    \Log::warning('Unbekannter Task-Typ: ' . $task->type);
                    throw new \InvalidArgumentException('Unknown task type: ' . $task->type);
            }
            
            // Cron tasks are recurring — don't mark them as permanently executed
            if (empty($task->cron_expression)) {
                $task->executed = true;
                $task->executed_at = now();
                $task->save();
                
                // If run_once is enabled, deactivate the task after execution
                if ($task->run_once) {
                    $task->active = false;
                    $task->save();
                    \Log::info('ScheduledTaskService: Task ' . $task->id . ' deactivated (run_once)');
                }
            }
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

    /**
     * Get recipients for a survey based on token type.
     * This is used by both SurveyService and ScheduledTaskService.
     */
    private function getSurveyRecipients(\App\Models\Survey $survey): array
    {
        $recipients = [];

        switch ($survey->response_token_type) {
            case 'reservation_email':
                if ($survey->event_id) {
                    $reservations = \App\Models\Reservation::whereHas('event', function($q) use ($survey) {
                        $q->where('id', $survey->event_id);
                    })->get(['email', 'site_token']);

                    foreach ($reservations as $reservation) {
                        try {
                            $recipients[] = [
                                'email' => $reservation->email,
                                'token' => $reservation->site_token ?? hash('sha256', $reservation->email),
                            ];
                        } catch (\Exception $e) {
                            // Skip if decryption fails
                        }
                    }
                }
                break;

            case 'user_account':
                $users = \App\Models\User::where('active', true)->get(['email']);
                foreach ($users as $user) {
                    $recipients[] = [
                        'email' => $user->email,
                        'token' => $user->api_token ?? hash('sha256', $user->email),
                    ];
                }
                break;

            case 'anonymous':
            default:
                if ($survey->event_id) {
                    $reservations = \App\Models\Reservation::whereHas('event', function($q) use ($survey) {
                        $q->where('id', $survey->event_id);
                    })->get(['site_token']);

                    foreach ($reservations as $reservation) {
                        $recipients[] = [
                            'email' => null,
                            'token' => $reservation->site_token ?? (string) \Illuminate\Support\Str::uuid(),
                        ];
                    }
                }
                break;
        }

        return $recipients;
    }
}

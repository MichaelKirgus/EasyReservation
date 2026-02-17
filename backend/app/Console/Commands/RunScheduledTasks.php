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
        \Log::info('RunScheduledTasks: Starting execution');
        
        // 1. Relative Zeiten berechnen
        $tasks = ScheduledTask::where('executed', false)->get();
        \Log::info('RunScheduledTasks: Found ' . $tasks->count() . ' unexecuted tasks');

        foreach ($tasks as $task) {
            if (!empty($task->cron_expression)) {
                // Update cron-based next_run_at
                try {
                    $nextRunAt = $task->getNextRunAtAttribute();
                    if ($nextRunAt && (!$task->next_run_at || !$task->next_run_at->eq($nextRunAt))) {
                        $task->next_run_at = $nextRunAt;
                        $task->save();
                        \Log::info('RunScheduledTasks: Updated next_run_at for cron task ' . $task->id . ' to ' . $nextRunAt->toIso8601String());
                    }
                } catch (\Throwable $e) {
                    Log::error('RunScheduledTasks: Error updating cron run time for task ' . $task->id . ': ' . $e->getMessage());
                }
            } elseif ($task->reference_type === 'event' && $task->reference_id && $task->relative_to && $task->relative_offset_minutes !== null) {
                // Existing relative time logic
                $event = \App\Models\Event::find($task->reference_id);
                if ($event && $event->{$task->relative_to}) {
                    $calculated = $event->{$task->relative_to}->copy()->addMinutes($task->relative_offset_minutes);
                    // Nur aktualisieren, wenn run_at unterschiedlich ist
                    if (!$task->run_at || !$task->run_at->eq($calculated)) {
                        $task->run_at = $calculated;
                        $task->save();
                        \Log::info('RunScheduledTasks: Updated run_at for task ' . $task->id . ' to ' . $task->run_at->toIso8601String());
                    }
                }
            }
        }

        // 2. Fällige Tasks ausführen
        $dueTasks = ScheduledTask::where('active', true)
          ->where('executed', false)
          ->where(function($outer) {
              $outer->where(function($query) {
                  // Standard tasks with run_at (not cron-based)
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

        \Log::info('RunScheduledTasks: Found ' . $dueTasks->count() . ' due tasks to execute');

        foreach ($dueTasks as $task) {
            try {
                // Wenn reference_id null: für alle zukünftigen Events ausführen
                if ($task->reference_type === 'event' && ($task->reference_id === null || $task->reference_id === 0)) {
                    \Log::info('RunScheduledTasks: Executing task ' . $task->id . ' for all future events');
                    $futureEvents = \App\Models\Event::where('start_at', '>=', now())->get();
                    foreach ($futureEvents as $event) {
                        switch ($task->type) {
                            case 'email_broadcast':
                                \Log::info('RunScheduledTasks: Sending email broadcast to event ' . $event->id);
                                $service = app(EmailBroadcastService::class);
                                $service->sendTemplateToReservationList($event->id, $task->options['template_id'] ?? null);
                                break;
                            case 'change_setting':
                                $key = $task->options['setting_key'] ?? null;
                                $value = $task->options['setting_value'] ?? null;
                                if ($key !== null) {
                                    \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                                    Log::info('RunScheduledTasks: Setting changed: ' . $key . ' => ' . $value);
                                }
                                break;
                            default:
                                Log::warning('RunScheduledTasks: Unknown task type: ' . $task->type);
                        }
                    }
                    // Cron tasks are recurring — only mark non-cron tasks as executed
                    if (!empty($task->cron_expression)) {
                        $task->last_run_at = now();
                        $task->executed = false;
                        $task->save();
                        try {
                            $nextRunAt = $task->getNextRunAtAttribute();
                            if ($nextRunAt) {
                                $task->next_run_at = $nextRunAt;
                                $task->save();
                            }
                        } catch (\Throwable $e) {
                            Log::error('RunScheduledTasks: Error updating cron next run for task ' . $task->id);
                        }
                    } else {
                        $task->executed = true;
                        $task->executed_at = now();
                        $task->save();
                    }
                    
                    continue;
                }
                
                // Standard: nur für das eine Event oder global
                \Log::info('RunScheduledTasks: Executing task ' . $task->id . ' (type: ' . $task->type . ')');
                
                switch ($task->type) {
                    case 'email_broadcast':
                        \Log::info('RunScheduledTasks: Sending email broadcast for task ' . $task->id);
                        $service = app(EmailBroadcastService::class);
                        $service->sendTemplateToReservationList($task->options['event_id'] ?? $task->reference_id, $task->options['template_id'] ?? null);
                        break;
                    case 'change_setting':
                        $key = $task->options['setting_key'] ?? null;
                        $value = $task->options['setting_value'] ?? null;
                        if ($key !== null) {
                            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                            Log::info('RunScheduledTasks: Setting changed: ' . $key . ' => ' . $value);
                        }
                        break;
                    default:
                        Log::warning('RunScheduledTasks: Unknown task type: ' . $task->type);
                }
                
                // Cron tasks are recurring — only mark non-cron tasks as executed
                if (!empty($task->cron_expression)) {
                    $task->last_run_at = now();
                    $task->executed = false;
                    $task->save();
                    try {
                        $nextRunAt = $task->getNextRunAtAttribute();
                        if ($nextRunAt) {
                            $task->next_run_at = $nextRunAt;
                            $task->save();
                        }
                    } catch (\Throwable $e) {
                        Log::error('RunScheduledTasks: Error updating cron next run for task ' . $task->id);
                    }
                } else {
                    $task->executed = true;
                    $task->executed_at = now();
                    $task->save();
                }
                
                \Log::info('RunScheduledTasks: Task ' . $task->id . ' completed successfully');
            } catch (\Throwable $e) {
                Log::error('RunScheduledTasks: Error executing task ' . $task->id . ': ' . $e->getMessage());
                Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            }
        }
        
        \Log::info('RunScheduledTasks: Execution completed');
        return 0;
    }
}

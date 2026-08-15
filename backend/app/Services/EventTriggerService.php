<?php
namespace App\Services;

use App\Models\EventTrigger;
use App\Models\Reservation;
use App\Services\SettingsService;
use App\Services\PlaceholderService;
use App\Jobs\SendEventTriggerJob;

class EventTriggerService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PlaceholderService $placeholderService,
    ) {}

    /**
     * Handle all triggers for a given event type and context.
     *
     * @param string $eventType
     * @param array $context
     */
    public function handle(string $eventType, array $context = [])
    {
        $now = now();
        /** @var \Illuminate\Database\Eloquent\Collection<int, EventTrigger> $triggers */
        $triggers = EventTrigger::query()->where('event_type', $eventType)
            ->where('active', true)
            ->get();

        foreach ($triggers as $trigger) {
            // Cooldown check
            if ($trigger->cooldown_seconds > 0 && $trigger->last_triggered_at && $now->diffInSeconds($trigger->last_triggered_at) < $trigger->cooldown_seconds) {
                continue;
            }
            // Delay: schedule for later if needed
            if ($trigger->delay_seconds > 0) {
                SendEventTriggerJob::dispatch($trigger->id, $eventType, $context)->delay($trigger->delay_seconds);
                // Update last_triggered_at immediately so cooldown window starts now,
                // preventing duplicate dispatches during the delay period
                $trigger->last_triggered_at = $now;
                $trigger->save();
                continue;
            }
            // Re-Evaluation: sofort prüfen
            if (!$this->shouldFireTrigger($trigger, $context)) {
                continue;
            }
            try {
                $this->applyContextPlaceholders($context);
                $this->executeAction($trigger, $context);
            } catch (\Throwable $e) {
                \Log::error('EventTriggerService: Trigger #' . $trigger->id . ' (' . $trigger->event_type . ') failed: ' . $e->getMessage());
                continue;
            } finally {
                $this->placeholderService->clearContextPlaceholders();
            }
            $trigger->last_triggered_at = $now;
            $trigger->save();
        }
    }

    // Prüft, ob das Event noch zutrifft (Re-Evaluation)
    public function shouldFireTrigger(EventTrigger $trigger, array $context = []): bool
    {
        // Beispielhafte Checks für bekannte Event-Typen
        switch ($trigger->event_type) {
            case 'reservation_full':
                $max = (int) ($this->settings->get('reservation_max', 0) ?? 0);
                $current = Reservation::query()->count();
                return $max > 0 && $current >= $max;
            case 'reservation_enabled':
                return (int) ($this->settings->get('reservation_enabled', 0) ?? 0) === 1;
            case 'reservation_disabled':
                return (int) ($this->settings->get('reservation_enabled', 0) ?? 0) === 0;
            case 'waitlist_enabled':
                return (int) ($this->settings->get('waitlist_enabled', 0) ?? 0) === 1;
            case 'waitlist_disabled':
                return (int) ($this->settings->get('waitlist_enabled', 0) ?? 0) === 0;
            case 'reservation_added':
                // Always fires when triggered - context contains the reservation
                return true;
            case 'reservation_removed':
                // Always fires when triggered - context contains the reservation
                return true;
            case 'reservation_canceled':
                // Always fires when triggered - context contains the reservation
                return true;
            case 'waitlist_entry_added':
                // Always fires when triggered - context contains the waitlist entry
                return true;
            case 'waitlist_entry_removed':
                // Always fires when triggered - context contains the waitlist entry
                return true;
            case 'application_error':
                // Always fires when triggered - context contains the error message
                return true;
            case 'setting_changed':
                // Always fires when triggered - context contains the changed settings
                return true;
            case 'login_succeeded':
            case 'login_failed':
            case 'logout':
                // Always fires when triggered - context contains the user/login details
                return true;
            default:
                return true; // Für andere Events ggf. anpassen
        }
    }

    public function executeAction(EventTrigger $trigger, array $context)
    {
        // Event triggers execute action lists only.
        $this->executeActionListForTrigger($trigger, $context);
    }

    private function executeActionListForTrigger(EventTrigger $trigger, array $context)
    {
        $actionListId = $trigger->action_list_id;
        if (!$actionListId) return;

        // Apply context placeholders
        $this->applyContextPlaceholders($context);

        $service = app(\App\Services\ActionListService::class);
        
        try {
            $service->executeActionList($actionListId, $context);
            \Log::info('EventTriggerService: Action list execution completed for trigger ' . $trigger->id);
        } catch (\Throwable $e) {
            \Log::error('EventTriggerService: Action list execution failed for trigger ' . $trigger->id . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply context-specific placeholders to the PlaceholderService before executing an action.
     */
    /**
     * Apply context-specific placeholders to the PlaceholderService before executing an action.
     */
    public function applyContextPlaceholders(array $context): void
    {
        $map = [];
        
        // Add event placeholders if present
        if (isset($context['event']) && $context['event'] instanceof \App\Models\Event) {
            $this->placeholderService->setEvent($context['event']);
        }
        
        // Add reservation placeholders if present
        if (isset($context['reservation']) && $context['reservation'] instanceof \App\Models\Reservation) {
            $this->placeholderService->setReservation($context['reservation']);
        }
        
        // Add user placeholders if present (e.g. login/logout triggers)
        if (isset($context['user']) && $context['user'] instanceof \App\Models\User) {
            $this->placeholderService->setUser($context['user']);
        }
        
        // Add waitlist_entry placeholders if present
        if (isset($context['waitlist_entry']) && $context['waitlist_entry'] instanceof \App\Models\WaitlistEntry) {
            $map['payload'] = $context['waitlist_entry']->payload ?? [];
        }
        
        // Add error_message placeholder if present
        if (isset($context['error_message'])) {
            $map['error_message'] = (string) $context['error_message'];
        }

        // Add setting_changed placeholders if present
        if (isset($context['changed_settings'])) {
            $map['changed_settings'] = (string) $context['changed_settings'];
        }
        if (isset($context['changed_by'])) {
            $map['changed_by'] = (string) $context['changed_by'];
        }

        // Add login/logout placeholders if present
        if (isset($context['login_identifier'])) {
            $map['login_identifier'] = (string) $context['login_identifier'];
        }
        if (isset($context['login_ip'])) {
            $map['login_ip'] = (string) $context['login_ip'];
        }
        
        if (!empty($map)) {
            $this->placeholderService->setContextPlaceholders($map);
        }
    }
  
}

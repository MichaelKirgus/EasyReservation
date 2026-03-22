<?php
namespace App\Services;

use App\Models\EventTrigger;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\SettingsService;
use App\Services\PlaceholderService;
use App\Jobs\SendEventTriggerJob;
use Illuminate\Support\Carbon;

class EventTriggerService
{
    public function __construct(
        private readonly EmailBroadcastService $emailBroadcast,
        private readonly WebhookService $webhookService,
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
        $triggers = EventTrigger::where('event_type', $eventType)
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
            default:
                return true; // Für andere Events ggf. anpassen
        }
    }

    public function executeAction(EventTrigger $trigger, array $context)
    {
        if ($trigger->action_type === 'email') {
            $this->sendEmailForTrigger($trigger, $context);
        } elseif ($trigger->action_type === 'webhook') {
            $this->sendWebhookForTrigger($trigger, $context);
        } elseif ($trigger->action_type === 'action_list') {
            $this->executeActionListForTrigger($trigger, $context);
        }
    }

    private function executeActionListForTrigger(EventTrigger $trigger, array $context)
    {
        // Check both template_id (legacy) and action_list_id
        $actionListId = $trigger->template_id ?? $trigger->action_list_id;
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

    private function sendEmailForTrigger(EventTrigger $trigger, array $context)
    {
        $templateId = $trigger->template_id;
        if (!$templateId) return;

        // Empfänger bestimmen
        $recipients = [];
        if ($trigger->recipient_attendees) {
            $recipients = array_merge($recipients, Reservation::query()->get()->map(fn($r) => ['name' => $r->display_name, 'email' => $r->email, 'payload' => $r->payload ?? []])->toArray());
        }
        if ($trigger->recipient_waitlist) {
            $recipients = array_merge($recipients, WaitlistEntry::query()->get()->map(fn($r) => ['name' => $r->display_name, 'email' => $r->email, 'payload' => $r->payload ?? []])->toArray());
        }
        if ($trigger->recipient_admins) {
            $adminRecipients = \App\Models\User::where('role', 'admin')->orWhere('role', 'superadmin')->pluck('email', 'name')->map(fn($email, $name) => ['name' => $name, 'email' => $email])->toArray();
            $recipients = array_merge($recipients, $adminRecipients);
        }
        if ($trigger->recipient_moderators) {
            $moderatorRecipients = \App\Models\User::where('role', 'moderator')->pluck('email', 'name')->map(fn($email, $name) => ['name' => $name, 'email' => $email])->toArray();
            $recipients = array_merge($recipients, $moderatorRecipients);
        }
        if (!empty($trigger->custom_recipients)) {
            $customs = preg_split('/[\n,]+/', $trigger->custom_recipients);
            foreach ($customs as $email) {
                $email = trim($email);
                if ($email !== '') {
                    $recipients[] = ['name' => $email, 'email' => $email];
                }
            }
        }
        // Deduplicate
        $recipients = collect($recipients)->filter(fn($r) => !empty($r['email']))->unique('email')->values()->toArray();
        if (count($recipients) === 0) return;

        // Sende E-Mail an alle Empfänger
        foreach ($recipients as $recipient) {
            $this->emailBroadcast->queueBroadcast(
                $templateId,
                'custom',
                false,
                [],
                [],
                [$recipient],
                true
            );
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
        
        // Add waitlist_entry placeholders if present
        if (isset($context['waitlist_entry']) && $context['waitlist_entry'] instanceof \App\Models\WaitlistEntry) {
            $map['payload'] = $context['waitlist_entry']->payload ?? [];
        }
        
        // Add error_message placeholder if present
        if (isset($context['error_message'])) {
            $map['error_message'] = (string) $context['error_message'];
        }
        
        if (!empty($map)) {
            $this->placeholderService->setContextPlaceholders($map);
        }
    }
  
    private function sendWebhookForTrigger(EventTrigger $trigger, array $context)
    {
        // Prefer webhook_template_id if set, fallback to direct webhook_url
        if ($trigger->webhook_template_id) {
            // Use the template's own payload_template (with placeholders resolved).
            // Do NOT override the payload — the template defines the format the endpoint expects.
            $template = \App\Models\WebhookTemplate::find($trigger->webhook_template_id);
            if ($template) {
                $headers = [];
                if ($template->headers_template) {
                    try {
                        $headers = json_decode($template->headers_template, true) ?: [];
                    } catch (\Throwable $e) {
                        $headers = [];
                    }
                }
                $payload = json_decode($template->payload_template, true) ?: [];
                \App\Jobs\SendWebhookJob::dispatch(
                    $template->url,
                    $payload,
                    $headers,
                    $trigger->id,
                    $trigger->event_type
                );
            }
            return;
        }

        if (!$trigger->webhook_url) return;

        // Serialize Eloquent models to arrays for clean JSON encoding
        $serializedContext = array_map(
            fn ($v) => $v instanceof \Illuminate\Database\Eloquent\Model ? $v->toArray() : $v,
            $context,
        );

        $payload = [
            'event' => $trigger->event_type,
            'context' => $serializedContext,
            'trigger_id' => $trigger->id,
            'fired_at' => now()->toIso8601String(),
        ];
        \App\Jobs\SendWebhookJob::dispatch(
            $trigger->webhook_url,
            $payload,
            [],
            $trigger->id,
            $trigger->event_type
        );
    }
}

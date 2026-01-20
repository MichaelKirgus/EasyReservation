<?php
namespace App\Services;

use App\Models\EventTrigger;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Jobs\SendEventTriggerJob;
use Illuminate\Support\Carbon;

class EventTriggerService
{
    public function __construct(
        private readonly EmailBroadcastService $emailBroadcast,
        private readonly WebhookService $webhookService,
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
            if ($trigger->last_triggered_at && $now->diffInSeconds($trigger->last_triggered_at) < $trigger->cooldown_seconds) {
                continue;
            }
            // Delay: schedule for later if needed
            if ($trigger->delay_seconds > 0) {
                SendEventTriggerJob::dispatch($trigger->id, $eventType, $context)->delay($trigger->delay_seconds);
                continue;
            }
            // Re-Evaluation: sofort prüfen
            if (!$this->shouldFireTrigger($trigger, $context)) {
                continue;
            }
            $this->executeAction($trigger, $context);
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
                $max = (int) (setting('reservation_max') ?? 0);
                $current = Reservation::query()->count();
                return $max > 0 && $current >= $max;
            case 'reservation_enabled':
                return (int) (setting('reservation_enabled') ?? 0) === 1;
            case 'reservation_disabled':
                return (int) (setting('reservation_enabled') ?? 0) === 0;
            case 'waitlist_enabled':
                return (int) (setting('waitlist_enabled') ?? 0) === 1;
            case 'waitlist_disabled':
                return (int) (setting('waitlist_enabled') ?? 0) === 0;
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
        }
    }

    private function sendEmailForTrigger(EventTrigger $trigger, array $context)
    {
        $templateId = $trigger->template_id;
        if (!$templateId) return;

        // Empfänger bestimmen
        $recipients = [];
        if ($trigger->recipient_attendees) {
            $recipients = array_merge($recipients, Reservation::query()->pluck('email', 'display_name')->map(fn($email, $name) => ['name' => $name, 'email' => $email])->values()->toArray());
        }
        if ($trigger->recipient_waitlist) {
            $recipients = array_merge($recipients, WaitlistEntry::query()->pluck('email', 'display_name')->map(fn($email, $name) => ['name' => $name, 'email' => $email])->values()->toArray());
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

    private function sendWebhookForTrigger(EventTrigger $trigger, array $context)
    {
        if (!$trigger->webhook_url) return;
        $payload = [
            'event' => $trigger->event_type,
            'context' => $context,
            'trigger_id' => $trigger->id,
            'fired_at' => now()->toIso8601String(),
        ];
        $this->webhookService->send($trigger->webhook_url, $payload);
    }
}

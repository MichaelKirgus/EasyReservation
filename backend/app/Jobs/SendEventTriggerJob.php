<?php
namespace App\Jobs;

use App\Models\EventTrigger;
use App\Services\EventTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEventTriggerJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $triggerId,
        public string $eventType,
        public array $context = []
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->triggerId;
    }

    public function handle(EventTriggerService $service)
    {
        $trigger = EventTrigger::find($this->triggerId);
        if (!$trigger || !$trigger->active) return;

        // Cooldown re-check: another trigger may have fired during the delay
        if ($trigger->cooldown_seconds > 0 && $trigger->last_triggered_at) {
            $secondsSinceLastTrigger = now()->diffInSeconds($trigger->last_triggered_at);
            // Only skip if cooldown hasn't elapsed AND this isn't the delayed execution
            // from the original dispatch (last_triggered_at was set at dispatch time,
            // so if delay_seconds has passed, the cooldown from that dispatch has been served)
            if ($secondsSinceLastTrigger < $trigger->cooldown_seconds && $secondsSinceLastTrigger < $trigger->delay_seconds) {
                return;
            }
        }

        // Re-Evaluation: Prüfe, ob das Event noch zutrifft
        if (!$service->shouldFireTrigger($trigger, $this->context)) return;

        try {
            $service->applyContextPlaceholders($this->context);
            $service->executeAction($trigger, $this->context);
        } catch (\Throwable $e) {
            \Log::error('SendEventTriggerJob: Trigger #' . $trigger->id . ' (' . $trigger->event_type . ') failed: ' . $e->getMessage());
            throw $e;
        } finally {
            app(\App\Services\PlaceholderService::class)->clearContextPlaceholders();
        }

        $trigger->last_triggered_at = now();
        $trigger->save();
    }
}

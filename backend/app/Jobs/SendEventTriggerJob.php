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
        // Re-Evaluation: Prüfe, ob das Event noch zutrifft
        if (!$service->shouldFireTrigger($trigger, $this->context)) return;
        $service->executeAction($trigger, $this->context);
        $trigger->last_triggered_at = now();
        $trigger->save();
    }
}

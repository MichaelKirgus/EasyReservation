<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\Concerns\LogsJob;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsJob;

    public string $url;
    public array $payload;
    public array $headers;
    public ?int $triggerId;
    public ?string $eventType;
    public ?int $scheduledTaskId;

    public function __construct(string $url, array $payload = [], array $headers = [], ?int $triggerId = null, ?string $eventType = null, ?int $scheduledTaskId = null)
    {
        $this->url = $url;
        $this->payload = $payload;
        $this->headers = $headers;
        $this->triggerId = $triggerId;
        $this->eventType = $eventType;
        $this->scheduledTaskId = $scheduledTaskId;
    }

    public function handle(): void
    {
        $queue = $this->resolveQueueName();
        $workerName = config('app.worker_name');
        $startTime = now();
        $monotonicStart = $this->monotonicNow();

        // --- Automatic placeholder resolution ---
        $placeholderService = app(\App\Services\PlaceholderService::class);
        $resolvedUrl = $placeholderService->replaceString($this->url);
        $resolvedPayload = $this->payload;
        // If payload is an array, resolve recursively
        if (is_array($resolvedPayload)) {
            $resolvedPayload = json_decode($placeholderService->replaceString(json_encode($resolvedPayload)), true) ?? $resolvedPayload;
        } elseif (is_string($resolvedPayload)) {
            $resolvedPayload = json_decode($placeholderService->replaceString($resolvedPayload), true) ?? $resolvedPayload;
        }
        $resolvedHeaders = $this->headers;
        if (is_array($resolvedHeaders)) {
            $resolvedHeaders = json_decode($placeholderService->replaceString(json_encode($resolvedHeaders)), true) ?? $resolvedHeaders;
        } elseif (is_string($resolvedHeaders)) {
            $resolvedHeaders = json_decode($placeholderService->replaceString($resolvedHeaders), true) ?? $resolvedHeaders;
        }

        $jobStartData = [
            'url' => $resolvedUrl,
            'payload' => $resolvedPayload,
            'headers' => $resolvedHeaders,
            'trigger_id' => $this->triggerId,
            'event_type' => $this->eventType,
            'scheduled_task_id' => $this->scheduledTaskId,
        ];
        $this->logJob(
            'SendWebhookJob',
            'started',
            "Webhook send started for {$resolvedUrl}",
            $jobStartData,
            null,
            $startTime,
            null,
            $queue
        );

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withHeaders($resolvedHeaders)->post($resolvedUrl, $resolvedPayload);
            $finishTime = now();
            $runtimeMs = $this->runtimeMs($monotonicStart);
            Log::debug('SendWebhookJob: runtime_ms value (success)', ['runtime_ms' => $runtimeMs]);
            $jobCompleteData = [
                'url' => $resolvedUrl,
                'payload' => $resolvedPayload,
                'headers' => $resolvedHeaders,
                'trigger_id' => $this->triggerId,
                'event_type' => $this->eventType,
                'scheduled_task_id' => $this->scheduledTaskId,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'status' => 'success',
                'timestamp' => $finishTime->toISOString(),
            ];
            Log::info('SendWebhookJob: Webhook sent successfully', $jobCompleteData);
            $this->logJob(
                'SendWebhookJob',
                'success',
                "Webhook sent to {$resolvedUrl} (Status: {$response->status()})",
                $jobCompleteData,
                $runtimeMs,
                $startTime,
                $finishTime,
                $queue
            );
        } catch (\Throwable $e) {
            $finishTime = now();
            $runtimeMs = $this->runtimeMs($monotonicStart);
            Log::debug('SendWebhookJob: runtime_ms value (error)', ['runtime_ms' => $runtimeMs]);
            $jobErrorData = [
                'url' => $resolvedUrl,
                'payload' => $resolvedPayload,
                'headers' => $resolvedHeaders,
                'trigger_id' => $this->triggerId,
                'event_type' => $this->eventType,
                'scheduled_task_id' => $this->scheduledTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'status' => 'failed',
                'timestamp' => $finishTime->toISOString(),
            ];
            Log::error('SendWebhookJob: Webhook send failed', $jobErrorData);
            $this->logJob(
                'SendWebhookJob',
                'failed',
                "Webhook send failed for {$resolvedUrl}: {$e->getMessage()}",
                $jobErrorData,
                $runtimeMs,
                $startTime,
                $finishTime,
                $queue
            );
            throw $e;
        }
    }

    private function monotonicNow(): float
    {
        return function_exists('hrtime')
            ? hrtime(true) / 1_000_000_000
            : microtime(true);
    }

    private function runtimeMs(float $start): int
    {
        $elapsedMs = (int) round(($this->monotonicNow() - $start) * 1000);

        return $elapsedMs < 0 ? 0 : $elapsedMs;
    }

    private function resolveQueueName(): ?string
    {
        if (!empty($this->queue)) {
            return $this->queue;
        }

        if (method_exists($this, 'queue')) {
            try {
                $queue = $this->queue();
                if (is_string($queue) && $queue !== '') {
                    return $queue;
                }
            } catch (\Throwable $e) {
                // ignore and fall through
            }
        }

        if (!empty($this->connection)) {
            return $this->connection;
        }

        return config('queue.default');
    }
}
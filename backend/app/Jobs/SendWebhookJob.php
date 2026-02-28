<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\JobLog;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $queue = $this->queue ?? ($this->onQueue ?? null);
        $workerName = config('app.worker_name');
        $startTime = now();
        $jobStartData = [
            'url' => $this->url,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'trigger_id' => $this->triggerId,
            'event_type' => $this->eventType,
            'scheduled_task_id' => $this->scheduledTaskId,
        ];
        JobLog::create([
            'job' => 'SendWebhookJob',
            'queue' => $queue,
            'worker_name' => $workerName,
            'message' => "Webhook send started for {$this->url}",
            'status' => 'started',
            'started_at' => $startTime,
            'details' => json_encode($jobStartData),
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withHeaders($this->headers)->post($this->url, $this->payload);
            $finishTime = now();
            $jobCompleteData = [
                'url' => $this->url,
                'payload' => $this->payload,
                'headers' => $this->headers,
                'trigger_id' => $this->triggerId,
                'event_type' => $this->eventType,
                'scheduled_task_id' => $this->scheduledTaskId,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'status' => 'success',
                'timestamp' => $finishTime->toISOString(),
            ];
            Log::info('SendWebhookJob: Webhook sent successfully', $jobCompleteData);
            JobLog::create([
                'job' => 'SendWebhookJob',
                'queue' => $queue,
                'worker_name' => $workerName,
                'message' => "Webhook sent to {$this->url} (Status: {$response->status()})",
                'status' => 'success',
                'runtime_ms' => $finishTime->diffInMilliseconds($startTime),
                'finished_at' => $finishTime,
                'details' => json_encode($jobCompleteData),
            ]);
        } catch (\Throwable $e) {
            $finishTime = now();
            $jobErrorData = [
                'url' => $this->url,
                'payload' => $this->payload,
                'headers' => $this->headers,
                'trigger_id' => $this->triggerId,
                'event_type' => $this->eventType,
                'scheduled_task_id' => $this->scheduledTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'status' => 'failed',
                'timestamp' => $finishTime->toISOString(),
            ];
            Log::error('SendWebhookJob: Webhook send failed', $jobErrorData);
            JobLog::create([
                'job' => 'SendWebhookJob',
                'queue' => $queue,
                'worker_name' => $workerName,
                'message' => "Webhook send failed for {$this->url}: {$e->getMessage()}",
                'status' => 'failed',
                'runtime_ms' => $finishTime->diffInMilliseconds($startTime),
                'finished_at' => $finishTime,
                'details' => json_encode($jobErrorData),
            ]);
            throw $e;
        }
    }
}
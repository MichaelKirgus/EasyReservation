<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class WebhookService
{
    public function send(string $url, array $payload = [], array $headers = []): void
    {
        \Log::info('WebhookService: Sending webhook to URL ' . $url);
        
        if (empty($url)) {
            \Log::error('WebhookService: Empty URL provided');
            throw new \InvalidArgumentException('URL cannot be empty');
        }
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            \Log::error('WebhookService: Invalid URL format: ' . $url);
            throw new \InvalidArgumentException('Invalid URL format: ' . $url);
        }
        
        try {
            \Log::debug('Webhook payload:', $payload);
            \Log::debug('Webhook headers:', $headers);
            
            $response = Http::timeout(10)->withHeaders($headers)->post($url, $payload);
            
            if ($response->failed()) {
                $statusCode = $response->status();
                $body = $response->body();
                \Log::error('Webhook request failed with status ' . $statusCode . ': ' . $body);
                throw new \RuntimeException('Webhook request failed with status ' . $statusCode);
            }
            
            \Log::info('Webhook sent successfully to ' . $url . ' (Status: ' . $response->status() . ')');
        } catch (\Throwable $e) {
            \Log::error('WebhookService: Error sending webhook to ' . $url . ': ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
    
    /**
     * Sendet ein Webhook-Template mit optionalem Payload-Override (z.B. für Scheduled Tasks).
     */
    public function sendTemplate($id, $payloadOverride = [])
    {
        $template = \App\Models\WebhookTemplate::findOrFail($id);
        $placeholderService = app(\App\Services\PlaceholderService::class);

        // Compute replacements once and reuse for URL, headers, and payload
        $replacements = $placeholderService->replacements();

        // Resolve placeholders in URL
        $url = strtr($template->url ?? '', $replacements);

        // Payload und Header-Template auflösen
        $payload = strtr($template->payload_template ?? '', $replacements);
        $headersArr = [];
        if ($template->headers_template) {
            try {
                $headersArr = json_decode(strtr($template->headers_template, $replacements), true) ?: [];
            } catch (\Throwable $e) {
                $headersArr = [];
            }
        }
        // Payload-Override (z.B. für Scheduled Tasks)
        $finalPayload = !empty($payloadOverride) ? $payloadOverride : (json_decode($payload, true) ?: []);
        $this->send($url, $finalPayload, $headersArr);
    }
}

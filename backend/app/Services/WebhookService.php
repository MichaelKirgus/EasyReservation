<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class WebhookService
{
    public function send(string $url, array $payload = [], array $headers = []): void
    {
        try {
            Http::timeout(10)->withHeaders($headers)->post($url, $payload);
        } catch (\Throwable $e) {
            // Fehlerbehandlung/Logging nach Bedarf
        }
    }
    
    /**
     * Sendet ein Webhook-Template mit optionalem Payload-Override (z.B. für Scheduled Tasks).
     */
    public function sendTemplate($id, $payloadOverride = [])
    {
        $template = \App\Models\WebhookTemplate::findOrFail($id);
        $placeholderService = app(\App\Services\PlaceholderService::class);

        // Platzhalter-Kontext: Für Testzwecke ggf. leer oder Dummy-Daten
        $context = [];
        // Payload und Header-Template auflösen
        $payload = $template->payload_template;
        $headers = $template->headers_template;
        // Platzhalter ersetzen
        $payload = $placeholderService->replaceString($payload);
        $headersArr = [];
        if ($headers) {
            try {
                $headersArr = json_decode($placeholderService->replaceString($headers), true) ?: [];
            } catch (\Throwable $e) {
                $headersArr = [];
            }
        }
        // Payload-Override (z.B. für Scheduled Tasks)
        $finalPayload = !empty($payloadOverride) ? $payloadOverride : (json_decode($payload, true) ?: []);
        $this->send($template->url, $finalPayload, $headersArr);
    }
}

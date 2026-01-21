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
}

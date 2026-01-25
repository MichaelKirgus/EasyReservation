<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookTemplate;
use Illuminate\Http\Request;

class WebhookTemplateController extends Controller
{
    public function index()
    {
        return response()->json(WebhookTemplate::all());
    }

    public function show($id)
    {
        $template = WebhookTemplate::findOrFail($id);
        return response()->json($template);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'url' => 'required|string|max:500',
            'payload_template' => 'required|string',
            'headers_template' => 'nullable|string',
        ]);
        $template = WebhookTemplate::create($data);
        return response()->json($template, 201);
    }

    public function update(Request $request, $id)
    {
        $template = WebhookTemplate::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'url' => 'required|string|max:500',
            'payload_template' => 'required|string',
            'headers_template' => 'nullable|string',
        ]);
        $template->update($data);
        return response()->json($template);
    }

    public function destroy($id)
    {
        $template = WebhookTemplate::findOrFail($id);
        $template->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Test-execute a webhook template (send a test webhook).
     */
    public function test($id)
    {
        try {
            $template = WebhookTemplate::findOrFail($id);
            $placeholderService = app(\App\Services\PlaceholderService::class);
            $webhookService = app(\App\Services\WebhookService::class);

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
            // Versenden
            $webhookService->send($template->url, json_decode($payload, true) ?: [], $headersArr);
            return response()->json(['success' => true, 'message' => 'Webhook wurde gesendet.']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage(),
            ], 500);
        }
    }
}

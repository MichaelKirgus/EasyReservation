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
        return response()->json(['success' => true, 'message' => __('webhook_template_deleted')]);
    }

    /**
     * Clone an existing webhook template under a new name.
     */
    public function clone(Request $request, $id)
    {
        $source = WebhookTemplate::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $clone = $source->replicate();
        $clone->name = $data['name'];
        $clone->save();

        return response()->json($clone, 201);
    }

    /**
     * Test-execute a webhook template (send a test webhook).
     */
    public function test($id)
    {
        \Log::info('WebhookTemplateController: test called for template ID ' . $id);
        
        try {
            $template = WebhookTemplate::findOrFail($id);
            $placeholderService = app(\App\Services\PlaceholderService::class);
            $webhookService = app(\App\Services\WebhookService::class);

            \Log::debug('WebhookTemplateController: Found template', [
                'id' => $template->id,
                'name' => $template->name,
                'url' => $template->url
            ]);

            // Platzhalter-Kontext: Für Testzwecke ggf. leer oder Dummy-Daten
            $context = [];
            // Payload und Header-Template auflösen
            $payload = $template->payload_template;
            $headers = $template->headers_template;
            
            \Log::debug('WebhookTemplateController: Original payload', ['payload' => $payload]);
            \Log::debug('WebhookTemplateController: Original headers', ['headers' => $headers]);
            
            // Platzhalter ersetzen
            $payload = $placeholderService->replaceString($payload);
            $headersArr = [];
            if ($headers) {
                try {
                    $headers = $placeholderService->replaceString($headers);
                    $headersArr = json_decode($headers, true) ?: [];
                } catch (\Throwable $e) {
                    \Log::warning('WebhookTemplateController: Failed to parse headers as JSON', ['error' => $e->getMessage()]);
                    $headersArr = [];
                }
            }
            
            $payloadArray = json_decode($payload, true) ?: [];
            \Log::debug('WebhookTemplateController: Final payload after replacement', ['payload' => $payloadArray]);
            \Log::debug('WebhookTemplateController: Final headers', ['headers' => $headersArr]);

            // Send
            $webhookService->send($template->url, $payloadArray, $headersArr);
            
            return response()->json([
                'success' => true,
                'message' => __('webhook_sent_successfully'),
                'details' => [
                    'url' => $template->url,
                    'payload' => $payloadArray,
                    'headers' => $headersArr
                ]
            ]);
        } catch (\Throwable $e) {
            \Log::error('WebhookTemplateController: Error testing template ' . $id . ': ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('error_generic', ['message' => $e->getMessage()]),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}

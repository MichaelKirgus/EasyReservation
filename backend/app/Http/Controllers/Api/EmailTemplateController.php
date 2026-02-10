<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PlaceholderService;
use App\Services\CustomPlaceholderService;

class EmailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(EmailTemplate::query()->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
        ]);

        $template = EmailTemplate::create([
            'name' => $data['name'],
            'type' => $data['type'] ?? 'validation',
            'subject' => $data['subject'],
            'body' => $data['body'],
        ]);

        return response()->json($template, 201);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:50'],
            'subject' => ['sometimes', 'string'],
            'body' => ['sometimes', 'string'],
        ]);

        $emailTemplate->fill($data);
        $emailTemplate->save();

        return response()->json($emailTemplate);
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->delete();

        return response()->json(['message' => __('email_template_deleted')]);
    }

    public function preview(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string'],
            'email' => ['nullable', 'string', 'email'],
        ]);

        $recipient = [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
        ];

        // Get all placeholders
        $placeholders = app(PlaceholderService::class)->replacements($recipient);
        
        // Replace placeholders in subject and body, keeping placeholder name if empty
        $resolvedSubject = $this->replaceWithEmptyPlaceholders($emailTemplate->subject, $placeholders);
        $resolvedBody = $this->replaceWithEmptyPlaceholders($emailTemplate->body, $placeholders);

        return response()->json([
            'template_id' => $emailTemplate->id,
            'name' => $emailTemplate->name,
            'subject' => $resolvedSubject,
            'body' => $resolvedBody,
            'placeholders_used' => array_keys($placeholders),
        ]);
    }

    private function replaceWithEmptyPlaceholders(string $value, array $replacements): string
    {
        if ($value === '') {
            return $value;
        }

        // Use strtr which replaces all occurrences at once
        // If a replacement is empty, we want to show the placeholder name instead
        foreach ($replacements as $placeholder => $replacement) {
            if ($replacement === '' || $replacement === null) {
                // Keep the placeholder name when it's empty
                continue;
            }
            $value = str_replace($placeholder, $replacement, $value);
        }

        return $value;
    }
}

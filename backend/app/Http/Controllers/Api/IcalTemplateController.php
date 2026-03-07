<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IcalTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IcalTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = IcalTemplate::query()
            ->orderBy('id')
            ->get();
        
        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $template = IcalTemplate::create([
            'name' => $data['name'],
            'content' => $data['content'] ?? null,
        ]);

        return response()->json($template, 201);
    }

    public function update(Request $request, IcalTemplate $icalTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $icalTemplate->fill($data);
        $icalTemplate->save();

        return response()->json($icalTemplate);
    }

    public function destroy(IcalTemplate $icalTemplate): JsonResponse
    {
        $icalTemplate->delete();

        return response()->json(['message' => __('ical_template_deleted')]);
    }

    public function preview(Request $request, IcalTemplate $icalTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string'],
            'email' => ['nullable', 'string', 'email'],
        ]);

        // Get all placeholders
        $placeholders = app(\App\Services\PlaceholderService::class)->replacements($data['name'] ?? null, $data['email'] ?? null);
        
        // Replace placeholders in content, keeping placeholder name if empty
        $resolvedContent = $this->replaceWithEmptyPlaceholders($icalTemplate->content ?? '', $placeholders);

        return response()->json([
            'template_id' => $icalTemplate->id,
            'name' => $icalTemplate->name,
            'content' => $resolvedContent,
            'placeholders_used' => array_keys($placeholders),
        ]);
    }

    private function replaceWithEmptyPlaceholders(?string $value, array $replacements): string
    {
        if ($value === null || $value === '') {
            return $value ?? '';
        }

        // Use strtr which replaces all occurrences at once
        foreach ($replacements as $placeholder => $replacement) {
            if ($replacement === '' || $replacement === null) {
                continue;
            }
            $value = str_replace($placeholder, $replacement, $value);
        }

        return $value;
    }
}

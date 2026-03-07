<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttachmentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttachmentTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = AttachmentTemplate::query()
            ->orderBy('id')
            ->get();
        
        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $template = AttachmentTemplate::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($template, 201);
    }

    public function update(Request $request, AttachmentTemplate $attachmentTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $attachmentTemplate->fill($data);
        $attachmentTemplate->save();

        return response()->json($attachmentTemplate);
    }

    public function destroy(AttachmentTemplate $attachmentTemplate): JsonResponse
    {
        $attachmentTemplate->delete();

        return response()->json(['message' => __('attachment_template_deleted')]);
    }

    public function attachments(AttachmentTemplate $attachmentTemplate): JsonResponse
    {
        $attachments = $attachmentTemplate->attachments()
            ->orderBy('id')
            ->get();
        
        return response()->json($attachments);
    }
}

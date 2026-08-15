<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttachmentTemplate;
use App\Models\AttachmentTemplateAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentUploadController extends Controller
{
    /**
     * Upload a file to an attachment template.
     */
    public function upload(Request $request, AttachmentTemplate $attachmentTemplate): JsonResponse
    {
        $validated = $this->validateUpload($request);

        // Derive the stored extension from the detected MIME type, never from the
        // client-supplied filename/extension, to prevent disguising executable files.
        $extension = $this->extensionForMimeType($validated['file']->getMimeType());
        $storedFilename = Str::uuid() . '.' . $extension;
        
        // Store file in public disk under attachment_templates directory
        $storagePath = 'attachment_templates/' . $storedFilename;
        $validated['file']->storeAs('attachment_templates', $storedFilename, 'public');

        // Get file size and mime type
        $filePath = storage_path('app/public/' . $storagePath);
        $fileSize = filesize($filePath);
        $mimeType = $validated['file']->getMimeType();

        // Create database record
        $attachment = AttachmentTemplateAttachment::create([
            'attachment_template_id' => $attachmentTemplate->id,
            'original_filename' => $validated['file']->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'storage_path' => $storagePath,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * Delete an attachment from storage and database.
     */
    public function destroy(AttachmentTemplate $attachmentTemplate, AttachmentTemplateAttachment $attachment): JsonResponse
    {
        // Verify attachment belongs to template
        if ($attachment->attachment_template_id !== $attachmentTemplate->id) {
            return response()->json(['error' => 'Attachment does not belong to this template'], 400);
        }

        // Delete file from storage
        if (Storage::disk('public')->exists($attachment->storage_path)) {
            Storage::disk('public')->delete($attachment->storage_path);
        }

        // Delete database record
        $attachment->delete();

        return response()->json(['message' => __('attachment_deleted')]);
    }

    /**
     * Validate the upload request.
     */
    private function validateUpload(Request $request): array
    {
        return $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:text/plain,text/html,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,image/jpeg,image/png,image/gif,image/webp',
                'max:10240', // 10MB in KB
            ],
        ]);
    }

    /**
     * Map a validated MIME type to a safe, fixed file extension.
     */
    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'text/plain' => 'txt',
            'text/html' => 'html',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}

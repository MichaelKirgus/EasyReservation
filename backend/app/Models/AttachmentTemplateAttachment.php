<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentTemplateAttachment extends Model
{
    protected $fillable = [
        'attachment_template_id',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'storage_path',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(AttachmentTemplate::class, 'attachment_template_id');
    }
}

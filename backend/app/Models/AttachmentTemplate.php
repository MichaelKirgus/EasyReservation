<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttachmentTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(AttachmentTemplateAttachment::class, 'attachment_template_id');
    }

    public function emailTemplates()
    {
        return $this->hasMany(EmailTemplate::class, 'attachment_template_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'subject',
        'body',
        'cc',
        'bcc',
        'transport_group_id',
        'ical_template_id',
        'attachment_template_id',
    ];

    public function transportGroup(): BelongsTo
    {
        return $this->belongsTo(MailTransportGroup::class, 'transport_group_id');
    }

    public function icalTemplate(): BelongsTo
    {
        return $this->belongsTo(IcalTemplate::class, 'ical_template_id');
    }

    public function attachmentTemplate(): BelongsTo
    {
        return $this->belongsTo(AttachmentTemplate::class, 'attachment_template_id');
    }
}

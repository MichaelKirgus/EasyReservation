<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTrigger extends Model
{
    protected $fillable = [
        'event_type',
        'action_type',
        'template_id',
        'webhook_url',
        'delay_seconds',
        'cooldown_seconds',
        'last_triggered_at',
        'active',
        'meta',
        'recipient_attendees',
        'recipient_waitlist',
        'recipient_admins',
        'recipient_moderators',
        'custom_recipients',
        'webhook_template_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'meta' => 'array',
        'last_triggered_at' => 'datetime',
        'recipient_attendees' => 'boolean',
        'recipient_waitlist' => 'boolean',
        'recipient_admins' => 'boolean',
        'recipient_moderators' => 'boolean',
    ];

    public function webhookTemplate()
    {
        return $this->belongsTo(WebhookTemplate::class);
    }
}

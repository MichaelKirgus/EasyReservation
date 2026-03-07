<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = [
        'title',
        'start_at',
        'end_at',
        'location_id',
        'capacity_override',
        'active',
        'notes',
        'auto_close_minutes_before',
        'auto_email_template_id',
        'auto_email_offset_minutes_before',
        'auto_email_sent_at',
        'uuid',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'active' => 'boolean',
        'auto_email_sent_at' => 'datetime',
    ];

    public function location(): ?BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if (!$event->uuid) {
                $event->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}

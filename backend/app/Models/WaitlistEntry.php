<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WaitlistEntry extends Model
{
    protected $fillable = [
        'display_name',
        'email',
        'payload',
        'status',
        'reservation_id',
        'promoted_at',
        'date_added',
        'undo_token',
        'undo_used_at',
        'site_token', // hinzugefügt
    ];

    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'promoted_at' => 'datetime',
        'undo_used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WaitlistEntry $entry) {
            if (empty($entry->date_added)) {
                $entry->date_added = now();
            }
        });
    }
}

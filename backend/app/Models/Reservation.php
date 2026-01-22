<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Reservation extends Model
{
    protected $fillable = [
        'display_name',
        'email',
        'payload',
        'undo_token',
        'date_added',
        'from_waitlist',
        'site_token',
    ];

    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'from_waitlist' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            if (empty($reservation->date_added)) {
                $reservation->date_added = now();
            }
            if (empty($reservation->undo_token)) {
                $reservation->undo_token = (string) Str::uuid();
            }
        });
    }
}

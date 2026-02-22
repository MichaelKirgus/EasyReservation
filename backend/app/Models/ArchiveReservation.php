<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ArchiveReservation extends Model
{
    protected $fillable = [
        'archive_id',
        'original_reservation_id',
        'display_name',
        'email',
        'payload',
        'date_added',
        'site_token',
        'email_encrypted',
    ];

    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'email_encrypted' => 'boolean',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function getEmailAttribute($value): string
    {
        if ($this->email_encrypted) {
            return Crypt::decryptString($value);
        }

        return $value;
    }

    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = Crypt::encryptString($value);
        $this->attributes['email_encrypted'] = true;
    }
}

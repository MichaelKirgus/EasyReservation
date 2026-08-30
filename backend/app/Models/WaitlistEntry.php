<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class WaitlistEntry extends Model
{
    use HasFactory;

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
        'email_encrypted',
    ];

    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'promoted_at' => 'datetime',
        'undo_used_at' => 'datetime',
        'email_encrypted' => 'boolean',
    ];

    public function getEmailAttribute($value)
    {
        if ($this->email_encrypted) {
            return Crypt::decryptString($value);
        }

        return $value;
    }

    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = Crypt::encryptString($value);
        $this->attributes['email_encrypted'] = true;
    }

    protected static function booted(): void
    {
        static::creating(function (WaitlistEntry $entry) {
            if (empty($entry->date_added)) {
                $entry->date_added = now();
            }
        });
    }
}

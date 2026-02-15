<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
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
        'email_encrypted',
    ];

    protected $casts = [
        'payload' => 'array',
        'date_added' => 'datetime',
        'from_waitlist' => 'boolean',
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

    public function emailValidations()
    {
        return $this->hasMany(\App\Models\EmailValidation::class, 'reservation_id');
    }

    public function waitlistEntries()
    {
        return $this->hasMany(\App\Models\WaitlistEntry::class, 'reservation_id');
    }

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

        static::deleting(function (Reservation $reservation) {
            // Lösche abhängige EmailValidations und WaitlistEntries über die reservation_id
            $reservation->emailValidations()->delete();
            $reservation->waitlistEntries()->delete();
        });
    }
}

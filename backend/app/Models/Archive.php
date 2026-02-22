<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Archive extends Model
{
    protected $fillable = [
        'name',
        'description',
        'store_emails',
    ];

    protected $casts = [
        'store_emails' => 'boolean',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(ArchiveReservation::class, 'archive_id');
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(ArchiveWaitlistEntry::class, 'archive_id');
    }
}

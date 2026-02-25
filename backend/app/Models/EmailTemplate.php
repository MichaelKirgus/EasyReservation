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
    ];

    public function transportGroup(): BelongsTo
    {
        return $this->belongsTo(MailTransportGroup::class, 'transport_group_id');
    }
}

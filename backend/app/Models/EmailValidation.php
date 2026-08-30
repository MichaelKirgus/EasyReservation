<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailValidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'display_name',
        'email',
        'payload',
        'token',
        'status',
        'requires_admin_approval',
        'expires_at',
        'validated_at',
        'approved_at',
        'completed_at',
        'reservation_id',
        'waitlist_entry_id',
        'last_error',
        'site_token',
    ];

    protected $casts = [
        'payload' => 'array',
        'requires_admin_approval' => 'boolean',
        'expires_at' => 'datetime',
        'validated_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

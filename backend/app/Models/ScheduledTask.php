<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'run_at',
        'options',
        'executed',
        'reference_type',
        'reference_id',
        'relative_to',
        'relative_offset_minutes',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'options' => 'array',
        'executed' => 'boolean',
        'reference_id' => 'integer',
        'relative_offset_minutes' => 'integer',
    ];
}

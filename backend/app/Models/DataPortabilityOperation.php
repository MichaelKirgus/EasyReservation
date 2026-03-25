<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataPortabilityOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'requested_by_user_id',
        'restore_mode',
        'source_file_path',
        'result_file_path',
        'selected_tables',
        'options',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'selected_tables' => 'array',
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}

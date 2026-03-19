<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'active',
        'field_key',
        'condition_type',
        'condition_operator',
        'condition_value',
        'error_message',
        'webhook_template_id',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function webhookTemplate(): BelongsTo
    {
        return $this->belongsTo(WebhookTemplate::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'url',
        'payload_template',
        'headers_template',
    ];
}

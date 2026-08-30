<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'type',
        'required',
        'options',
        'placeholder',
        'help_text',
        'text_align',
        'min_length',
        'max_length',
        'pattern',
        'order',
        'active',
        'visible_public',
        'visible_admin',
        'is_email',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'active' => 'boolean',
        'visible_public' => 'boolean',
        'visible_admin' => 'boolean',
        'is_email' => 'boolean',
    ];
}

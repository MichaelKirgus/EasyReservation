<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GlobalQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_text',
        'field_type',
        'is_required',
        'display_order',
        'options',
        'min_value',
        'max_value',
    ];

    protected $casts = [
        'options' => 'array',
        'min_value' => 'integer',
        'max_value' => 'integer',
    ];
}

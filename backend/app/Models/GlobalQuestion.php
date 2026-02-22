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
    ];

    protected $casts = [
        'options' => 'array',
    ];
}

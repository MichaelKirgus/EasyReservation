<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    use HasFactory;

    protected $fillable = [
        'question',
        'answer',
        'is_published',
        'position',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Faq $faq) {
            if ($faq->position === null) {
                $faq->position = (Faq::max('position') ?? 0) + 1;
            }

            if ($faq->is_published && $faq->published_at === null) {
                $faq->published_at = now();
            }
        });
    }
}

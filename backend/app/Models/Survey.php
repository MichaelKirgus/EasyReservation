<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Survey extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'event_id',
        'starts_at',
        'ends_at',
        'active',
        'response_token_type',
        'max_responses_per_user',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function questions()
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('display_order');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }
}

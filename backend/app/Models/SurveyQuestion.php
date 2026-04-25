<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SurveyQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'global_question_id',
        'question_text',
        'field_type',
        'is_required',
        'display_order',
        'options',
        'min_value',
        'max_value',
        'active',
    ];

    protected $casts = [
        'options' => 'array',
        'min_value' => 'integer',
        'max_value' => 'integer',
        'active' => 'boolean',
    ];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function globalQuestion()
    {
        return $this->belongsTo(GlobalQuestion::class, 'global_question_id');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class, 'question_id');
    }
}

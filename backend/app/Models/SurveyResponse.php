<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SurveyResponse extends Model
{
    protected $fillable = [
        'survey_id',
        'question_id',
        'responder_token',
        'response_text',
        'response_score',
        'is_responded',
    ];

    protected $casts = [
        'is_responded' => 'boolean',
        'response_score' => 'integer',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }

    /**
     * Check if a user has already responded to this survey with the given token
     */
    public static function hasResponded(string $surveyId, string $responderToken): bool
    {
        return self::where('survey_id', $surveyId)
            ->where('responder_token', $responderToken)
            ->where('is_responded', true)
            ->exists();
    }

    /**
     * Get or create a unique token for the responder
     */
    public static function getResponderToken(?string $email = null, ?string $siteToken = null): string
    {
        if ($email) {
            // Use hash of email for privacy
            return hash('sha256', trim($email));
        }

        if ($siteToken) {
            // Use site token directly
            return $siteToken;
        }

        // Generate random token
        return (string) Str::uuid();
    }
}

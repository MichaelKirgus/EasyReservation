<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

class SurveyService
{
    public function __construct(
        private readonly LinkBuildingService $linkBuilder,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Send surveys for events that have ended
     * This is called by scheduled tasks
     */
    public function sendSurveysForEndedEvents(): int
    {
        $sentCount = 0;

        // Find surveys linked to events that ended and haven't been sent yet
        $surveys = Survey::whereHas('event', function($query) {
            $query->where('end_at', '<=', now());
        })->whereDoesntHave('responses', function($query) {
            // Only include surveys where no responses exist (or all are empty)
            $query->where('is_responded', true);
        })->get();

        foreach ($surveys as $survey) {
            try {
                $recipients = $this->getSurveyRecipients($survey);

                if (empty($recipients)) {
                    Log::info("SurveyService: No recipients for survey {$survey->id}");
                    continue;
                }

                foreach ($recipients as $recipient) {
                    $responseToken = $recipient['token'] ?? (string) \Illuminate\Support\Str::uuid();
                    $surveyLink = route('public.survey.response', [
                        'surveyId' => $survey->id,
                        'token' => $responseToken,
                    ]);

                    // Build email body
                    $subject = "Survey: {$survey->title}";
                    $body = "<p>{$survey->description}</p>";
                    $body .= "<p>Please fill out our survey: <a href=\"{$surveyLink}\">{$surveyLink}</a></p>";

                    // Send email using existing job
                    dispatch(new \App\Jobs\SendMailJob(
                        config('mail.default'),
                        $recipient['email'] ?? '',
                        null,
                        $subject,
                        $body,
                        null,
                        null,
                        [],
                        config('mail.from.address'),
                        config('mail.from.name')
                    ));

                    $sentCount++;
                }

                Log::info("SurveyService: Sent survey {$survey->id} to {$sentCount} recipients");
            } catch (\Exception $e) {
                Log::error("SurveyService: Failed to send survey {$survey->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Get recipients for a survey based on token type
     */
    private function getSurveyRecipients(Survey $survey): array
    {
        $recipients = [];

        switch ($survey->response_token_type) {
            case 'reservation_email':
                if ($survey->event_id) {
                    $reservations = \App\Models\Reservation::whereHas('event', function($q) use ($survey) {
                        $q->where('id', $survey->event_id);
                    })->get(['email', 'site_token']);

                    foreach ($reservations as $reservation) {
                        try {
                            $recipients[] = [
                                'email' => $reservation->email,
                                'token' => $reservation->site_token ?? hash('sha256', $reservation->email),
                            ];
                        } catch (\Exception $e) {
                            // Skip if decryption fails
                        }
                    }
                }
                break;

            case 'user_account':
                $users = \App\Models\User::where('active', true)->get(['email']);
                foreach ($users as $user) {
                    $recipients[] = [
                        'email' => $user->email,
                        'token' => $user->api_token ?? hash('sha256', $user->email),
                    ];
                }
                break;

            case 'anonymous':
            default:
                if ($survey->event_id) {
                    $reservations = \App\Models\Reservation::whereHas('event', function($q) use ($survey) {
                        $q->where('id', $survey->event_id);
                    })->get(['site_token']);

                    foreach ($reservations as $reservation) {
                        $recipients[] = [
                            'email' => null,
                            'token' => $reservation->site_token ?? (string) \Illuminate\Support\Str::uuid(),
                        ];
                    }
                }
                break;
        }

        return $recipients;
    }

    /**
     * Check if a survey is currently active and accepting responses
     */
    public function canRespond(Survey $survey, string $token): array
    {
        $result = [
            'can_respond' => false,
            'message' => '',
        ];

        // Check if survey is active
        if (!$survey->active) {
            $result['message'] = __('survey_not_active');
            return $result;
        }

        // Check time window
        $now = now();
        if ($survey->starts_at && $now->lt($survey->starts_at)) {
            $result['message'] = __('survey_not_started');
            return $result;
        }
        if ($survey->ends_at && $now->gt($survey->ends_at)) {
            $result['message'] = __('survey_ended');
            return $result;
        }

        // Check if already responded
        if (SurveyResponse::hasResponded($survey->id, $token)) {
            $result['message'] = __('already_responded');
            return $result;
        }

        $result['can_respond'] = true;
        $result['message'] = __('ready_to_respond');

        return $result;
    }
}

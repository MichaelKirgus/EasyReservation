<?php

namespace App\Services;

use App\Models\EmailValidation;
use App\Models\Reservation;
use Illuminate\Support\Str;

class LinkBuildingService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Build validation link for email verification
     */
    public function buildValidationLink(EmailValidation $validation): string
    {
        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['v' => (string) $validation->token];

        if (!empty($validation->site_token)) {
            $params['t'] = $validation->site_token;
        } else if (!empty($validation->reservation_id)) {
            $reservation = Reservation::find($validation->reservation_id);
            if ($reservation && !empty($reservation->site_token)) {
                $params['t'] = $reservation->site_token;
            }
        }

        return $this->appendQuery($base, $params);
    }

    /**
     * Build undo link for reservations
     */
    public function buildUndoLink(Reservation $reservation): string
    {
        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['u' => (string) $reservation->undo_token];
        if (!empty($reservation->site_token)) {
            $params['t'] = $reservation->site_token;
        }

        return $this->appendQuery($base, $params);
    }

    /**
     * Build admin approval link for an email validation record.
     * Points to the admin API endpoint that approves the validation.
     */
    public function buildAdminApprovalLink(EmailValidation $validation): string
    {
        $appUrl = rtrim(config('app.url'), '/');

        return $appUrl . '/api/admin/email-validations/' . $validation->id . '/approve';
    }

    /**
     * Build survey response link for email templates.
     *
     * @param int $surveyId The survey ID
     * @param string|null $token Optional responder token (if not provided, a new one will be generated)
     */
    public function buildSurveyLink(int $surveyId, ?string $token = null): string
    {
        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['survey_id' => (string) $surveyId];
        
        if ($token) {
            $params['token'] = $token;
        } else {
            // Generate a unique token for the responder
            $params['token'] = (string) Str::uuid();
        }

        return $this->appendQuery($base, $params);
    }

    /**
     * Append query parameters to URL
     */
    public function appendQuery(string $base, array $params): string
    {
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base.$separator.http_build_query($params);
    }
}
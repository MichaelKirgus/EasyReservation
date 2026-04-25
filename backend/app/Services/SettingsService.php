<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public function all(): array
    {
        return Cache::remember('settings.all', now()->addMinutes(5), function () {
            $settings = [];
            foreach (Setting::all() as $setting) {
                $settings[$setting->name] = $setting->value;
            }
            return $settings;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function refresh(): void
    {
        Cache::forget('settings.all');
        $this->all();
    }

    public function isTokenRequired(): bool
    {
        return (int) $this->get('reservation_token_enabled', 0) === 1;
    }

    public function siteToken(): ?string
    {
        return $this->get('reservation_token');
    }

    public function loginRateLimitAttempts(): int
    {
        return (int) $this->get('login_rate_limit_attempts', 5);
    }

    public function loginRateLimitDecayMinutes(): int
    {
        return (int) $this->get('login_rate_limit_decay_minutes', 1);
    }

    /**
     * Session lifetime in minutes.
     * 0 = session cookie (expires when browser closes).
     * Default: 43200 (30 days).
     */
    public function sessionLifetimeMinutes(): int
    {
        return max(0, (int) $this->get('session_lifetime_minutes', 43200));
    }

    /**
     * Post-login redirect URL.
     * Default: '/moderation/dashboard'.
     */
    public function postLoginRedirectUrl(): string
    {
        return (string) $this->get('post_login_redirect_url', '/moderation/dashboard');
    }

    public function upcomingEvents(): array
    {
        $raw = (string) ($this->get('reservation_upcoming_events', '') ?? '');
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn ($v) => $v !== ''));
    }

    public function nextEvent(): ?string
    {
        $list = $this->upcomingEvents();
        if (count($list) === 0) {
            return null;
        }

        $now = time();
        foreach ($list as $item) {
            $ts = strtotime($item);
            if ($ts !== false && $ts >= $now) {
                return $item;
            }
        }

        return $list[0] ?? null;
    }

    public function upcomingEventsListFormatted(string $bullet = '• '): string
    {
        $list = $this->upcomingEvents();
        if (count($list) === 0) {
            return '';
        }

        return implode("\n", array_map(fn ($v) => $bullet.$v, $list));
    }

    /**
     * Default submission message for surveys.
     * Used when a survey does not have its own submission_message set.
     */
    public function surveyDefaultSubmissionMessage(): string
    {
        return (string) $this->get('survey_default_submission_message', '');
    }

    /**
     * Default already-responded message for surveys.
     * Used when a survey does not have its own already_responded_message set.
     */
    public function surveyDefaultAlreadyRespondedMessage(): string
    {
        return (string) $this->get('survey_default_already_responded_message', '');
    }
}

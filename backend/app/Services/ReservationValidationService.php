<?php

namespace App\Services;

use App\Models\FormField;

class ReservationValidationService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function nameIsValid(string $name): bool
    {
        $name = trim($name);
        return $name !== '';
    }

    public function emailIsValid(?string $email): bool
    {
        $email = trim((string) $email);
        $required = FormField::query()->where('is_email', true)->where('required', true)->where('active', true)->exists();

        if (! $required && $email === '') {
            return true;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }

    public function missingRequiredCheckboxes(?array $payload): array
    {
        $payload = is_array($payload) ? $payload : [];

        $requiredCheckboxes = FormField::query()
            ->where('type', 'checkbox')
            ->where('required', true)
            ->where('active', true)
            ->where('visible_public', true)
            ->get(['key', 'label']);

        $missing = [];

        foreach ($requiredCheckboxes as $field) {
            $value = $payload[$field->key] ?? null;

            $checked = match (true) {
                is_bool($value) => $value,
                is_numeric($value) => (int) $value === 1,
                is_string($value) => in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true),
                default => false,
            };

            if (! $checked) {
                $missing[] = $field->label ?? $field->key;
            }
        }

        return $missing;
    }

    /**
     * Prüft, ob eine E-Mail auf der Debug-Domain-Blacklist steht (mail_debug_domain_blacklist).
     * Diese Funktion ist nur für Debug-Zwecke gedacht, um Test-Domains vom Versand auszuschließen.
     */
    public function isDebugBlacklistedEmail(?string $email): bool
    {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domainBlacklist = $this->settings->get('mail_debug_domain_blacklist', '');
        if (!$domainBlacklist) return false;
        $blacklist = array_filter(array_map('trim', explode(',', $domainBlacklist)));
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        foreach ($blacklist as $blockedDomain) {
            if ($emailDomain === strtolower($blockedDomain)) {
                return true;
            }
        }
        return false;
    }
}

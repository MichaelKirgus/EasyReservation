<?php

namespace App\Services;

use App\Models\FormField;
use Illuminate\Support\Facades\Log;

class ReservationValidationService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function nameIsValid(string $name): bool
    {
        $name = trim($name);
        $min = (int) $this->settings->get('reservation_name_minchar', 0);
        $max = (int) $this->settings->get('reservation_name_maxchar', 0);
        $allowUnicode = (int) $this->settings->get('reservation_name_allowunicode', 1) === 1;
        $blockPlainEnabled = (int) $this->settings->get('reservation_name_blacklist_enable', 0) === 1;
        $blockPlain = (string) $this->settings->get('reservation_name_blacklist', '');
        $blockUnicodeEnabled = (int) $this->settings->get('reservation_name_blacklist_unicode_enable', 0) === 1;
        $blockUnicode = (string) $this->settings->get('reservation_name_blacklist_unicode_base64', '');
        $whitelistRegexEnabled = (int) $this->settings->get('reservation_name_whitelist_regex_enable', 0) === 1;
        $whitelistRegex = (string) $this->settings->get('reservation_name_whitelist_regex', '');

        $length = mb_strlen($name);

        if ($min > 0 && $length < $min) {
            Log::debug('Name validation failed: too short', [
                'name' => $name,
                'length' => $length,
                'min' => $min
            ]);
            return false;
        }

        if ($max > 0 && $length > $max) {
            Log::debug('Name validation failed: too long', [
                'name' => $name,
                'length' => $length,
                'max' => $max
            ]);
            return false;
        }

        if (! $allowUnicode) {
            $sanitized = filter_var($name, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
            if ($sanitized !== $name) {
                Log::debug('Name validation failed: unicode not allowed', [
                    'name' => $name,
                    'sanitized' => $sanitized
                ]);
                return false;
            }
        }

        if ($blockPlainEnabled && $blockPlain !== '') {
            $blocked = array_filter(array_map('trim', explode(',', $blockPlain)));
            foreach ($blocked as $item) {
                if ($item !== '' && str_contains(mb_strtolower($name), mb_strtolower($item))) {
                    Log::debug('Name validation failed: blocked plain string', [
                        'name' => $name,
                        'blocked' => $item
                    ]);
                    return false;
                }
            }
        }

        if ($blockUnicodeEnabled && $blockUnicode !== '') {
            $blocked = array_filter(array_map('trim', explode(',', $blockUnicode)));
            foreach ($blocked as $item) {
                $decoded = base64_decode($item, true);
                if ($decoded !== false && $decoded !== '' && str_contains($name, $decoded)) {
                    Log::debug('Name validation failed: blocked unicode string', [
                        'name' => $name,
                        'blocked_base64' => $item,
                        'blocked_decoded' => $decoded
                    ]);
                    return false;
                }
            }
        }

        if ($whitelistRegexEnabled && $whitelistRegex !== '') {
            $matches = @preg_match($whitelistRegex, $name) === 1;
            if (! $matches) {
                Log::debug('Name validation failed: whitelist regex not matched', [
                    'name' => $name,
                    'regex' => $whitelistRegex
                ]);
                return false;
            }
        }

        if ($name === '') {
            Log::debug('Name validation failed: name is empty');
            return false;
        }

        return true;
    }

    public function emailIsValid(?string $email): bool
    {
        $email = trim((string) $email);
        $required = FormField::query()->where('is_email', true)->where('required', true)->where('active', true)->exists();
        $whitelistEnabled = (int) $this->settings->get('reservation_email_whitelist_enable', 0) === 1;
        $whitelist = (string) $this->settings->get('reservation_email_whitelist', '');
        $regexEnabled = (int) $this->settings->get('reservation_email_whitelist_regex_enable', 0) === 1;
        $regex = (string) $this->settings->get('reservation_email_whitelist_regex', '');

        if (! $required && $email === '') {
            return true;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($whitelistEnabled && $whitelist !== '') {
            $domains = array_filter(array_map('trim', explode(',', $whitelist)));
            $allowed = false;
            foreach ($domains as $domain) {
                if ($domain !== '' && str_contains(mb_strtolower($email), mb_strtolower($domain))) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                return false;
            }
        }

        if ($regexEnabled && $regex !== '') {
            $matches = @preg_match($regex, $email) === 1;
            if (! $matches) {
                return false;
            }
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
}

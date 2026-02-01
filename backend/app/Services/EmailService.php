<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\EmailTemplate;
use App\Models\JobLog;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EmailService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IcsService $ics,
        private readonly PlaceholderService $placeholders,
        private readonly LinkBuildingService $linkBuilder,
    ) {
    }

    /**
     * Prüft, ob eine E-Mail auf der Debug-Domain-Blacklist steht (mail_debug_domain_blacklist).
     * Diese Funktion ist nur für Debug-Zwecke gedacht, um Test-Domains vom Versand auszuschließen.
     */
    private function isDebugBlacklistedEmail(?string $email): bool
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

    /**
     * Send a validation email for email verification
     */
    public function sendValidationEmail(array $mailerConfig, EmailValidation $validation): void
    {
        if (! $validation->email) {
            throw new \RuntimeException('E-Mail wird für die Validierung benötigt.');
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($validation->email)) {
            return;
        }

        $template = $this->resolveTemplate();
        $link = $this->linkBuilder->buildValidationLink($validation);

        // Use PlaceholderService for all placeholders including custom
        $replacements = $this->placeholders->replacements([
            'name' => $validation->display_name,
            'email' => $validation->email ?? '',
            'validation_link' => $link,
            'validation_link_html' => '<a href="'.$link.'">'.$link.'</a>',
            'undo_link' => '',
            'undo_link_html' => '',
            'attach_event_ical' => '',
        ]);

        $subject = strtr($template['subject'], $replacements);
        $body = strtr($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $validation->email, $validation->display_name, $subject, $body, $fromAddress, $fromName, $attachments);
    }

    /**
     * Send reservation notification email
     */
    public function sendReservationNotification(array $mailerConfig, Reservation $reservation, string $templateSettingKey, bool $includeUndoLink): void
    {
        if (! $reservation->email) {
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($reservation->email)) {
            return;
        }

        $templateId = (int) ($this->settings->get($templateSettingKey, 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }

        $undoLink = $includeUndoLink ? $this->linkBuilder->buildUndoLink($reservation) : '';
        $replacements = $this->placeholders->replacements([
            'name' => $reservation->display_name,
            'email' => $reservation->email ?? '',
            'undo_link' => $undoLink,
            'undo_link_html' => $includeUndoLink ? '<a href="'.$undoLink.'">'.$undoLink.'</a>' : '',
            'validation_link' => '',
            'validation_link_html' => '',
            'attach_event_ical' => '',
        ]);

        $template = $this->resolveTemplateById($templateId, 'Info zu deiner Reservierung', '<p>Hallo {{name}},</p><p>deine Reservierung für {{reservation_name}} war erfolgreich.</p><p><a href="{{undo_link}}">Reservierung stornieren</a></p>');
        $subject = $this->renderTemplate($template['subject'], $replacements);
        $body = $this->renderTemplate($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $reservation->email, $reservation->display_name, $subject, $body, $fromAddress, $fromName, $attachments);
    }

    /**
     * Send waitlist validation success email
     */
    public function sendWaitlistValidationSuccessEmail(array $mailerConfig, WaitlistEntry $entry): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_validation_success_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($entry->email)) {
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($entry->email)) {
            return;
        }

        try {
            // This would be handled by EmailBroadcastService in the current implementation
            // For now, we'll dispatch directly to SendMailJob with proper parameters
            $this->sendEmailFromTemplate($mailerConfig, $templateId, $entry);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Waitlist validation success email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    /**
     * Send waitlist cancelled email
     */
    public function sendWaitlistCancelledEmail(array $mailerConfig, WaitlistEntry $entry): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_cancel_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($entry->email)) {
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($entry->email)) {
            return;
        }

        try {
            // This would be handled by EmailBroadcastService in the current implementation
            // For now, we'll dispatch directly to SendMailJob with proper parameters
            $this->sendEmailFromTemplate($mailerConfig, $templateId, $entry);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Waitlist cancel email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    /**
     * Send waitlist promoted email
     */
    public function sendWaitlistPromotedEmail(array $mailerConfig, Reservation $reservation): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_promoted_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($reservation->email)) {
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($reservation->email)) {
            return;
        }

        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        try {
            // This would be handled by EmailBroadcastService in the current implementation
            // For now, we'll dispatch directly to SendMailJob with proper parameters
            $this->sendEmailFromTemplate($mailerConfig, $templateId, $reservation);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Waitlist promotion email failed', [
                'error' => $e->getMessage(),
                'reservation_id' => $reservation->id,
            ]);
        }
    }

    /**
     * Send email from template for a specific recipient
     */
    private function sendEmailFromTemplate(array $mailerConfig, int $templateId, $recipient): void
    {
        $template = EmailTemplate::query()->find($templateId);
        if (! $template) {
            return;
        }

        $replacements = $this->placeholders->replacements([
            'name' => $recipient->display_name ?? '',
            'email' => $recipient->email ?? '',
            'undo_link' => $recipient->undo_token ? $this->linkBuilder->buildUndoLink($recipient) : '',
            'undo_link_html' => $recipient->undo_token ? '<a href="'.$this->linkBuilder->buildUndoLink($recipient).'">'.$this->linkBuilder->buildUndoLink($recipient).'</a>' : '',
            'validation_link' => '',
            'validation_link_html' => '',
        ]);

        $subject = $this->renderTemplate($template->subject, $replacements);
        $body = $this->renderTemplate($template->body, $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $recipient->email, $recipient->display_name ?? $recipient->email, $subject, $body, $fromAddress, $fromName, $attachments);
    }

    /**
     * Render template with replacements
     */
    private function renderTemplate(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    /**
     * Resolve email template by ID or use default
     */
    private function resolveTemplateById(?int $templateId, string $defaultSubject, string $defaultBody): array
    {
        $template = $templateId ? EmailTemplate::query()->find($templateId) : null;
        if ($template) {
            return ['subject' => $template->subject, 'body' => $template->body];
        }

        return ['subject' => $defaultSubject, 'body' => $defaultBody];
    }

    /**
     * Resolve default validation template
     */
    private function resolveTemplate(): array
    {
        $templateId = $this->settings->get('email_validation_template_id');
        $template = $templateId ? EmailTemplate::query()->find($templateId) : null;

        $subject = 'Bitte E-Mail bestätigen';
        $body = <<<HTML
<p>Hallo {{name}},</p>
<p>bitte bestätige deine E-Mail-Adresse, um die Reservierung abzuschliessen.</p>
<p>{{validation_link_html}}</p>
<p>Falls der Link nicht klickbar ist, kopiere ihn in die Adresszeile: {{validation_link}}</p>
HTML;

        if ($template) {
            $subject = $template->subject;
            $body = $template->body;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Build mailer configuration
     */
    private function buildMailerConfig(): ?array
    {
        $host = $this->settings->get('mail_host');
        $port = (int) ($this->settings->get('mail_port') ?? 0);
        $username = $this->settings->get('mail_username');
        $password = $this->settings->get('mail_password');
        $encryption = $this->settings->get('mail_encryption', null) ?: null;

        if (! $host || ! $port) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'timeout' => null,
        ];
    }


    /**
     * Get attachments for template
     */
    private function attachmentsForTemplate(array $template): array
    {
        if (! $this->templateWantsIcs($template)) {
            return [];
        }

        $ics = $this->ics->nextEventAttachment();
        return $ics ? [$ics] : [];
    }

    /**
     * Check if template wants ICS attachment
     */
    private function templateWantsIcs(array $template): bool
    {
        return str_contains($template['subject'] ?? '', '{{attach_event_ical}}')
            || str_contains($template['body'] ?? '', '{{attach_event_ical}}');
    }
}
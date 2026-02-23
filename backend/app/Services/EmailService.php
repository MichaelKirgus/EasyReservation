<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\EmailTemplate;
use App\Models\EmailValidation;
use App\Models\JobLog;
use App\Models\Reservation;
use App\Models\Survey;
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
            throw new \RuntimeException(__('validation_invalid_email'));
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($validation->email)) {
            return;
        }

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

        $template = $this->resolveTemplate();
        
        if (!$template) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not resolve validation template');
            return;
        }

        $subject = strtr($template['subject'], $replacements);
        $body = strtr($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        // Merge template-specific CC/BCC with global CC/BCC
        $templateCc = $template['cc'] ?? null;
        $templateBcc = $template['bcc'] ?? null;
        
        $globalCc = $this->settings->get('mail_global_cc');
        $globalBcc = $this->settings->get('mail_global_bcc');

        // Merge template and global CC/BCC (remove duplicates)
        $cc = $this->mergeEmailAddresses($templateCc, $globalCc);
        $bcc = $this->mergeEmailAddresses($templateBcc, $globalBcc);

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $validation->email, $validation->display_name, $subject, $body, $fromAddress, $fromName, $attachments, $cc, $bcc);
    }

    /**
     * Send an admin approval notification email to the configured admin email address.
     * This is triggered when a user verifies their email and admin approval is required,
     * or when admin-only approval is active (no email validation step).
     */
    public function sendAdminApprovalEmail(array $mailerConfig, EmailValidation $validation): void
    {
        $adminEmail = trim((string) ($this->settings->get('email_validation_admin_email', '') ?? ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No valid admin approval email address configured');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($adminEmail)) {
            return;
        }

        $templateId = (int) ($this->settings->get('email_validation_admin_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No admin approval template configured');
            return;
        }

        $template = $this->resolveTemplateById($templateId);
        if (!$template) {
            \Illuminate\Support\Facades\Log::error('EmailService: Admin approval template not found for ID ' . $templateId);
            return;
        }

        $approvalLink = $this->linkBuilder->buildAdminApprovalLink($validation);

        // The user who needs approval is available via {{name}} and {{email}} placeholders
        $replacements = $this->placeholders->replacements([
            'name' => $validation->display_name,
            'email' => $validation->email ?? '',
            'admin_approval_link' => $approvalLink,
            'admin_approval_link_html' => '<a href="' . $approvalLink . '">' . $approvalLink . '</a>',
            'validation_link' => '',
            'validation_link_html' => '',
            'undo_link' => '',
            'undo_link_html' => '',
            'attach_event_ical' => '',
        ]);

        $subject = $this->renderTemplate($template['subject'], $replacements);
        $body = $this->renderTemplate($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        // Merge template-specific CC/BCC with global CC/BCC
        $templateCc = $template['cc'] ?? null;
        $templateBcc = $template['bcc'] ?? null;

        $globalCc = $this->settings->get('mail_global_cc');
        $globalBcc = $this->settings->get('mail_global_bcc');

        // Merge template and global CC/BCC (remove duplicates)
        $cc = $this->mergeEmailAddresses($templateCc, $globalCc);
        $bcc = $this->mergeEmailAddresses($templateBcc, $globalBcc);

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $adminEmail, 'Admin', $subject, $body, $fromAddress, $fromName, $attachments, $cc, $bcc);
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

        $template = $this->resolveTemplateById($templateId);
        
        if (!$template) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not resolve template for reservation notification');
            return;
        }
        
        $subject = $this->renderTemplate($template['subject'], $replacements);
        $body = $this->renderTemplate($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        // Use template-specific CC/BCC if set, otherwise use global
        $templateCc = $template['cc'] ?? null;
        $templateBcc = $template['bcc'] ?? null;
        
        $globalCc = $this->settings->get('mail_global_cc');
        $globalBcc = $this->settings->get('mail_global_bcc');

        // If template has CC/BCC, use those; otherwise fall back to global
        $cc = $templateCc ?: $globalCc;
        $bcc = $templateBcc ?: $globalBcc;

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $reservation->email, $reservation->display_name, $subject, $body, $fromAddress, $fromName, $attachments, $cc, $bcc);
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
            'survey_link' => '',
            'survey_link_html' => '',
        ]);

        $subject = $this->renderTemplate($template->subject, $replacements);
        $body = $this->renderTemplate($template->body, $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        // Use template-specific CC/BCC if set, otherwise use global
        $templateCc = $template->cc ?? null;
        $templateBcc = $template->bcc ?? null;
        
        $globalCc = $this->settings->get('mail_global_cc');
        $globalBcc = $this->settings->get('mail_global_bcc');

        // If template has CC/BCC, use those; otherwise fall back to global
        $cc = $templateCc ?: $globalCc;
        $bcc = $templateBcc ?: $globalBcc;

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $recipient->email, $recipient->display_name ?? $recipient->email, $subject, $body, $fromAddress, $fromName, $attachments, $cc, $bcc);
    }

    /**
     * Render template with replacements
     */
    private function renderTemplate(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    /**
     * Resolve email template by ID
     */
    private function resolveTemplateById(?int $templateId): ?array
    {
        if (!$templateId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No template ID provided');
            return null;
        }

        $template = EmailTemplate::query()->find($templateId);
        if ($template) {
            return ['subject' => $template->subject, 'body' => $template->body, 'cc' => $template->cc, 'bcc' => $template->bcc];
        }

        \Illuminate\Support\Facades\Log::error('EmailService: Template not found for ID ' . $templateId);
        return null;
    }

    /**
     * Resolve validation template
     */
    private function resolveTemplate(): ?array
    {
        $templateId = (int) ($this->settings->get('email_validation_template_id') ?? 0);
        
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::error('EmailService: No validation template ID configured');
            return null;
        }

        $template = EmailTemplate::query()->find($templateId);
        
        if (!$template) {
            \Illuminate\Support\Facades\Log::error('EmailService: Validation template not found for ID ' . $templateId);
            return null;
        }

        return ['subject' => $template->subject, 'body' => $template->body, 'cc' => $template->cc, 'bcc' => $template->bcc];
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

    /**
     * Send survey email to a recipient using the template system
     */
    public function sendSurveyEmail(array $mailerConfig, Survey $survey, string $recipientEmail, ?string $recipientName = null, ?int $templateId = null): void
    {
        if ($this->isDebugBlacklistedEmail($recipientEmail)) {
            return;
        }

        // Generate unique token for this recipient
        $responseToken = (string) \Illuminate\Support\Str::uuid();
        
        // Build survey link using LinkBuildingService
        $surveyLink = $this->linkBuilder->buildSurveyLink($survey->id, $responseToken);
        
        // Prepare replacements with placeholders
        $replacements = $this->placeholders->replacements([
            'name' => $recipientName ?? '',
            'email' => $recipientEmail,
            'undo_link' => '',
            'undo_link_html' => '',
            'validation_link' => '',
            'validation_link_html' => '',
            'survey_link' => $surveyLink,
            'survey_link_html' => '<a href="' . $surveyLink . '">' . $surveyLink . '</a>',
        ]);

        // Use provided template or default
        if ($templateId) {
            $template = EmailTemplate::query()->find($templateId);
        } else {
            // Try to find a survey-type template, otherwise use default
            $template = EmailTemplate::query()->where('type', 'survey')->first();
        }

        if (!$template) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not resolve survey email template');
            return;
        }

        $subject = $this->renderTemplate($template->subject, $replacements);
        $body = $this->renderTemplate($template->body, $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        // Merge template-specific CC/BCC with global CC/BCC
        $templateCc = $template->cc ?? null;
        $templateBcc = $template->bcc ?? null;

        $globalCc = $this->settings->get('mail_global_cc');
        $globalBcc = $this->settings->get('mail_global_bcc');

        // Merge template and global CC/BCC (remove duplicates)
        $cc = $this->mergeEmailAddresses($templateCc, $globalCc);
        $bcc = $this->mergeEmailAddresses($templateBcc, $globalBcc);

        SendMailJob::dispatch($mailerConfig, $recipientEmail, $recipientName, $subject, $body, $fromAddress, $fromName, [], $cc, $bcc);
    }

    /**
     * Merge email addresses from two sources, removing duplicates.
     */
    private function mergeEmailAddresses(?string $first, ?string $second): ?string
    {
        if (!$first && !$second) {
            return null;
        }

        // Parse both strings into arrays (comma-separated)
        $addresses = [];
        
        if ($first) {
            foreach (array_filter(array_map('trim', explode(',', $first))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $addresses[strtolower($addr)] = $addr;
                }
            }
        }

        if ($second) {
            foreach (array_filter(array_map('trim', explode(',', $second))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $addresses[strtolower($addr)] = $addr;
                }
            }
        }

        return !empty($addresses) ? implode(', ', $addresses) : null;
    }
}
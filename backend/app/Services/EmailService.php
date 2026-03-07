<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\AttachmentTemplateAttachment;
use App\Models\EmailTemplate;
use App\Models\EmailValidation;
use App\Models\JobLog;
use App\Models\MailAccount;
use App\Models\MailGroupAccount;
use App\Models\MailTransportGroup;
use App\Models\Reservation;
use App\Models\Survey;
use App\Models\WaitlistEntry;
use App\Models\EmailBlacklistDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IcsService $ics,
        private readonly PlaceholderService $placeholders,
        private readonly LinkBuildingService $linkBuilder,
        private readonly MailTransportService $mailTransportService,
    ) {
    }

    /**
     * Get mailer config from transport group with debug logging.
     */
    private function getMailerConfigFromTransportGroup(?int $transportGroupId): ?array
    {
        if (!$transportGroupId) {
            Log::error('EmailService: No transport group ID provided for email sending');
            return null;
        }

        $group = MailTransportGroup::query()->with('accounts.account')->find($transportGroupId);
        
        if (!$group) {
            Log::error('EmailService: Transport group not found', ['transport_group_id' => $transportGroupId]);
            return null;
        }

        // Get next account based on failover strategy
        $account = $this->mailTransportService->getNextAccount($group);
        
        if (!$account) {
            Log::error('EmailService: No available accounts in transport group', [
                'transport_group_id' => $transportGroupId,
                'group_name' => $group->name,
            ]);
            return null;
        }

        // Check rate limit
        if (!$this->mailTransportService->checkRateLimit($account)) {
            Log::warning('EmailService: Rate limit exceeded for account', [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'transport_group_id' => $transportGroupId,
            ]);
            return null;
        }

        // Get mailer config
        $mailerConfig = $this->mailTransportService->getMailerConfig($account);
        $this->lastTransportGroupInfo = [
            'transport_group_id' => $group->id,
            'transport_group_name' => $group->name,
            'transport_account_id' => $account->id,
            'transport_account_name' => $account->name,
            'retry_count' => $account->retry_count,
        ];
        Log::info('EmailService: Using transport group for email', [
            'transport_group_id' => $transportGroupId,
            'group_name' => $group->name,
            'account_id' => $account->id,
            'account_name' => $account->name,
            'host' => $account->host,
        ]);
        return $mailerConfig;
    }

    private function isDebugBlacklistedEmail(?string $email): bool
    {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Get domain from email
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));

        // Check database for blacklisted domains
        $blacklistEntry = EmailBlacklistDomain::where('domain', $emailDomain)
            ->where('active', true)
            ->first();

        return $blacklistEntry !== null;
    }

    /**
     * Send a validation email for email verification
     */
    public function sendValidationEmail(?int $transportGroupId, EmailValidation $validation): void
    {
        if (! $validation->email) {
            throw new \RuntimeException(__('validation_invalid_email'));
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($validation->email)) {
            return;
        }

        // Get mailer config from transport group
        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        
        if (!$mailerConfig) {
            Log::error('EmailService: Cannot send validation email - no valid mailer config', [
                'email' => $validation->email,
                'transport_group_id' => $transportGroupId,
            ]);
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
            Log::error('EmailService: Could not resolve validation template');
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

        $attachments = $this->attachmentsForTemplate([
            'subject' => $template['subject'] ?? null,
            'body' => $template['body'] ?? null,
        ]);

        Log::info('EmailService: Dispatching validation email', [
            'to_email' => $validation->email,
            'subject' => $subject,
            'transport_group_id' => $transportGroupId,
        ]);

        $tg = $this->lastTransportGroupInfo ?? [];
        SendMailJob::dispatch(
            $mailerConfig,
            $validation->email,
            $validation->display_name,
            $subject,
            $body,
            $fromAddress,
            $fromName,
            $attachments,
            $cc,
            $bcc,
            $tg['transport_group_id'] ?? null,
            $tg['transport_group_name'] ?? null,
            $tg['transport_account_id'] ?? null,
            $tg['transport_account_name'] ?? null,
            $tg['retry_count'] ?? null
        );
    }

    /**
     * Send an admin approval notification email to the configured admin email address.
     * This is triggered when a user verifies their email and admin approval is required,
     * or when admin-only approval is active (no email validation step).
     */
    public function sendAdminApprovalEmail(?int $transportGroupId, EmailValidation $validation): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting admin approval email sending', [
            'transport_group_id' => $transportGroupId,
            'validation_id' => $validation->id,
            'email' => $validation->email ?? 'unknown',
        ]);

        $adminEmail = trim((string) ($this->settings->get('email_validation_admin_email', '') ?? ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No valid admin approval email address configured');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($adminEmail)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Admin approval email blacklisted, skipping');
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

        $attachments = $this->attachmentsForTemplate([
            'subject' => $template['subject'] ?? null,
            'body' => $template['body'] ?? null,
        ]);

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No transport group ID provided for admin approval email');
            return;
        }

        // Use MailTransportService with failover
        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not build mailer config from transport group ' . $transportGroupId);
            return;
        }

        $tg = $this->lastTransportGroupInfo ?? [];
        SendMailJob::dispatch(
            $mailerConfig,
            $adminEmail,
            'Admin',
            $subject,
            $body,
            $fromAddress,
            $fromName,
            $attachments,
            $cc,
            $bcc,
            $tg['transport_group_id'] ?? null,
            $tg['transport_group_name'] ?? null,
            $tg['transport_account_id'] ?? null,
            $tg['transport_account_name'] ?? null,
            $tg['retry_count'] ?? null
        );
    }

    /**
     * Send reservation notification email
     */
    public function sendReservationNotification(?int $transportGroupId, Reservation $reservation, string $templateSettingKey, bool $includeUndoLink): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting reservation notification email sending', [
            'transport_group_id' => $transportGroupId,
            'reservation_id' => $reservation->id,
            'email' => $reservation->email ?? 'unknown',
            'template_setting_key' => $templateSettingKey,
        ]);

        if (! $reservation->email) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Reservation has no email, skipping');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($reservation->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Reservation email blacklisted, skipping');
            return;
        }

        $templateId = (int) ($this->settings->get($templateSettingKey, 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No template configured for reservation notification');
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

        $attachments = $this->attachmentsForTemplate([
            'subject' => $template['subject'],
            'body' => $template['body'],
        ]);

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No transport group ID provided for reservation notification');
            return;
        }

        // Use MailTransportService with failover
        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not build mailer config from transport group ' . $transportGroupId);
            return;
        }

        $tg = $this->lastTransportGroupInfo ?? [];
        SendMailJob::dispatch(
            $mailerConfig,
            $reservation->email,
            $reservation->display_name,
            $subject,
            $body,
            $fromAddress,
            $fromName,
            $attachments,
            $cc,
            $bcc,
            $tg['transport_group_id'] ?? null,
            $tg['transport_group_name'] ?? null,
            $tg['transport_account_id'] ?? null,
            $tg['transport_account_name'] ?? null,
            $tg['retry_count'] ?? null
        );
    }

    /**
     * Send waitlist validation success email
     */
    public function sendWaitlistValidationSuccessEmail(?int $transportGroupId, WaitlistEntry $entry): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting waitlist validation success email sending', [
            'transport_group_id' => $transportGroupId,
            'waitlist_entry_id' => $entry->id,
            'email' => $entry->email ?? 'unknown',
        ]);

        $templateId = (int) ($this->settings->get('email_waitlist_validation_success_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No waitlist validation success template configured');
            return;
        }
        if (empty($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Waitlist entry has no email, skipping');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Waitlist validation success email blacklisted, skipping');
            return;
        }

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No transport group ID provided for waitlist validation success email');
            return;
        }

        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        $tg = $this->lastTransportGroupInfo ?? [];
        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not build mailer config from transport group ' . $transportGroupId);
            return;
        }

        try {
            $this->sendEmailFromTemplateWithTransportInfo($mailerConfig, $templateId, $entry, $tg);
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
    public function sendWaitlistCancelledEmail(?int $transportGroupId, WaitlistEntry $entry): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting waitlist cancelled email sending', [
            'transport_group_id' => $transportGroupId,
            'waitlist_entry_id' => $entry->id,
            'email' => $entry->email ?? 'unknown',
        ]);

        $templateId = (int) ($this->settings->get('email_waitlist_cancel_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No waitlist cancel template configured');
            return;
        }
        if (empty($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Waitlist entry has no email, skipping');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Waitlist cancelled email blacklisted, skipping');
            return;
        }

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No transport group ID provided for waitlist cancelled email');
            return;
        }

        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not build mailer config from transport group ' . $transportGroupId);
            return;
        }

        try {
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
    public function sendWaitlistPromotedEmail(?int $transportGroupId, Reservation $reservation): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting waitlist promoted email sending', [
            'transport_group_id' => $transportGroupId,
            'reservation_id' => $reservation->id,
            'email' => $reservation->email ?? 'unknown',
        ]);

        $templateId = (int) ($this->settings->get('email_waitlist_promoted_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('EmailService: No waitlist promoted template configured');
            return;
        }
        if (empty($reservation->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Reservation has no email, skipping');
            return;
        }

        // Check if email is blacklisted
        if ($this->isDebugBlacklistedEmail($reservation->email)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Waitlist promoted email blacklisted, skipping');
            return;
        }

        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);

        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: No mailer config available for waitlist promoted email (missing/invalid transport group)');
            return;
        }

        try {
            $this->sendEmailFromTemplate($mailerConfig, $templateId, $reservation);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Waitlist promotion email failed', [
                'error' => $e->getMessage(),
                'reservation_id' => $reservation->id,
            ]);
        }
    }


    /**
     * Backward-compatible wrapper: send email using transport info without TG metadata
     */
    private function sendEmailFromTemplate(array $mailerConfig, int $templateId, $recipient): void
    {
        $this->sendEmailFromTemplateWithTransportInfo($mailerConfig, $templateId, $recipient, []);
    }

    /**
     * Send email from template for a specific recipient
     */
    private function sendEmailFromTemplateWithTransportInfo(array $mailerConfig, int $templateId, $recipient, array $tg = []): void
    {
        $template = EmailTemplate::query()->find($templateId);
        if (! $template) {
            return;
        }

        $undoLink = (!empty($recipient->undo_token))
            ? $this->linkBuilder->buildUndoLink($recipient)
            : '';

        $replacements = $this->placeholders->replacements([
            'name' => $recipient->display_name ?? '',
            'email' => $recipient->email ?? '',
            'undo_link' => $undoLink,
            'undo_link_html' => $undoLink ? '<a href="'.$undoLink.'">'.$undoLink.'</a>' : '',
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

        $attachments = $this->attachmentsForTemplate([
            'subject' => $template->subject,
            'body' => $template->body,
        ]);

        SendMailJob::dispatch(
            $mailerConfig,
            $recipient->email,
            $recipient->display_name ?? $recipient->email,
            $subject,
            $body,
            $fromAddress,
            $fromName,
            $attachments,
            $cc,
            $bcc,
            $tg['transport_group_id'] ?? null,
            $tg['transport_group_name'] ?? null,
            $tg['transport_account_id'] ?? null,
            $tg['transport_account_name'] ?? null,
            $tg['retry_count'] ?? null
        );
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
     * Get attachments for template
     */
   private function attachmentsForTemplate(array $template): array
   {
       $attachments = [];

       // Get ical_template_id from email template if not explicitly provided
       $icalTemplateId = $template['ical_template_id'] ?? null;
       
       // Add ICS attachment if requested
       if ($this->templateWantsIcs($template)) {
           $ics = $this->ics->nextEventAttachment($icalTemplateId);
           if ($ics) {
               $attachments[] = $ics;
           }
       }

       // Get attachment_template_id from email template
       $attachmentTemplateId = $template['attachment_template_id'] ?? null;
       
       // Add file attachments from attachment template
       if ($attachmentTemplateId) {
           $fileAttachments = AttachmentTemplateAttachment::where('attachment_template_id', $attachmentTemplateId)->get();
           
           foreach ($fileAttachments as $attachment) {
               try {
                   $filePath = storage_path('app/public/' . $attachment->storage_path);
                   if (file_exists($filePath)) {
                       $attachments[] = [
                           'name' => $attachment->original_filename,
                           'data' => file_get_contents($filePath),
                           'mime' => $attachment->mime_type,
                       ];
                   }
               } catch (\Exception $e) {
                   \Illuminate\Support\Facades\Log::warning('EmailService: Failed to load attachment', [
                       'attachment_id' => $attachment->id,
                       'error' => $e->getMessage(),
                   ]);
               }
           }
       }

       return $attachments;
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
    public function sendSurveyEmail(?int $transportGroupId, Survey $survey, string $recipientEmail, ?string $recipientName = null, ?int $templateId = null): void
    {
        \Illuminate\Support\Facades\Log::debug('EmailService: Starting survey email sending', [
            'transport_group_id' => $transportGroupId,
            'survey_id' => $survey->id,
            'email' => $recipientEmail,
        ]);

        if ($this->isDebugBlacklistedEmail($recipientEmail)) {
            \Illuminate\Support\Facades\Log::debug('EmailService: Survey email blacklisted, skipping');
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

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('EmailService: No transport group ID provided for survey email');
            return;
        }

        $mailerConfig = $this->getMailerConfigFromTransportGroup($transportGroupId);
        if (!$mailerConfig) {
            \Illuminate\Support\Facades\Log::error('EmailService: Could not build mailer config from transport group ' . $transportGroupId);
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

        $tg = $this->lastTransportGroupInfo ?? [];
        SendMailJob::dispatch(
            $mailerConfig,
            $recipientEmail,
            $recipientName,
            $subject,
            $body,
            $fromAddress,
            $fromName,
            [],
            $cc,
            $bcc,
            $tg['transport_group_id'] ?? null,
            $tg['transport_group_name'] ?? null,
            $tg['transport_account_id'] ?? null,
            $tg['transport_account_name'] ?? null,
            $tg['retry_count'] ?? null
        );
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
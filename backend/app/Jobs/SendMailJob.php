<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Illuminate\Support\Str;
use App\Models\JobLog;
use Illuminate\Support\Facades\Log;

class SendMailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array $mailerConfig
     * @param string $toEmail
     * @param string|null $toName
     * @param string $subject
     * @param string $body
     * @param string|null $fromAddress
     * @param string|null $fromName
     * @param array $attachments
     * @param string|null $globalCc
     * @param string|null $globalBcc
     * @param int|null $transportGroupId
     * @param string|null $transportGroupName
     * @param int|null $transportAccountId
     * @param string|null $transportAccountName
     */
    public function __construct(
        private readonly array $mailerConfig,
        private readonly string $toEmail,
        private readonly ?string $toName,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
        private readonly array $attachments = [],
        private readonly ?string $globalCc = null,
        private readonly ?string $globalBcc = null,
        private readonly ?int $transportGroupId = null,
        private readonly ?string $transportGroupName = null,
        private readonly ?int $transportAccountId = null,
        private readonly ?string $transportAccountName = null,
    ) {
    }

    public function handle(): void
    {
        // Zentrale Debug-Blacklist-Prüfung
        $validator = app(\App\Services\ReservationValidationService::class);
        if ($validator->isDebugBlacklistedEmail($this->toEmail)) {
            $emailDomain = strtolower(substr(strrchr($this->toEmail, '@'), 1));
            JobLog::create([
                'job' => 'SendMailJob',
                'message' => "Adress for domain ($emailDomain) not sent: {$this->toEmail}",
                'status' => 'skipped',
                'details' => json_encode([
                    'to_email' => $this->toEmail,
                    'domain' => $emailDomain,
                    'reason' => 'debug_blacklist'
                ])
            ]);
            Log::info('SendMailJob: Email skipped due to debug blacklist', [
                'to_email' => $this->toEmail,
                'domain' => $emailDomain
            ]);
            return;
        }

        // Log job start with comprehensive details
        $jobStartData = [
            'to_email' => $this->toEmail,
            'subject' => $this->subject,
            'has_attachments' => count($this->attachments) > 0,
            'attachment_count' => count($this->attachments),
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'global_cc' => $this->globalCc ? 'configured' : null,
            'global_bcc' => $this->globalBcc ? 'configured' : null,
            'transport_group_id' => $this->transportGroupId,
            'transport_group_name' => $this->transportGroupName,
            'transport_account_id' => $this->transportAccountId,
            'transport_account_name' => $this->transportAccountName,
        ];
        
        Log::info('SendMailJob: Starting email send process', $jobStartData);

        JobLog::create([
            'job' => 'SendMailJob',
            'message' => "Email send started for {$this->toEmail}",
            'status' => 'started',
            'details' => json_encode($jobStartData)
        ]);

        $mailerName = 'dynamic_'.md5(json_encode($this->mailerConfig)).'_'.Str::random(6);
        Config::set('mail.mailers.'.$mailerName, $this->mailerConfig);

        Mail::mailer($mailerName)->send([], [], function (Message $message) {
            $message->to($this->toEmail, $this->toName ?: $this->toEmail);
            if ($this->fromAddress) {
                $message->from($this->fromAddress, $this->fromName ?: $this->fromAddress);
            }
            
            // Add global CC if configured
            if ($this->globalCc) {
                $ccAddresses = array_filter(array_map('trim', explode(',', $this->globalCc)));
                foreach ($ccAddresses as $ccAddress) {
                    if (filter_var($ccAddress, FILTER_VALIDATE_EMAIL)) {
                        $message->cc($ccAddress);
                    }
                }
            }
            
            // Add global BCC if configured
            if ($this->globalBcc) {
                $bccAddresses = array_filter(array_map('trim', explode(',', $this->globalBcc)));
                foreach ($bccAddresses as $bccAddress) {
                    if (filter_var($bccAddress, FILTER_VALIDATE_EMAIL)) {
                        $message->bcc($bccAddress);
                    }
                }
            }
            
            $message->subject($this->subject);
            $message->html($this->body);

            foreach ($this->attachments as $attachment) {
                if (! isset($attachment['data'])) {
                    continue;
                }
                $name = $attachment['name'] ?? 'attachment';
                $mime = $attachment['mime'] ?? 'application/octet-stream';
                $message->attachData($attachment['data'], $name, ['mime' => $mime]);
            }
        });

        // Log job completion with comprehensive details
        $jobCompleteData = [
            'to_email' => $this->toEmail,
            'subject' => $this->subject,
            'global_cc' => $this->globalCc ? 'configured' : null,
            'global_bcc' => $this->globalBcc ? 'configured' : null,
            'transport_group_id' => $this->transportGroupId,
            'transport_group_name' => $this->transportGroupName,
            'transport_account_id' => $this->transportAccountId,
            'transport_account_name' => $this->transportAccountName,
            'status' => 'success',
            'timestamp' => now()->toISOString()
        ];

        Log::info('SendMailJob: Email sent successfully', $jobCompleteData);

        JobLog::create([
            'job' => 'SendMailJob',
            'message' => "Email sent to {$this->toEmail}: {$this->subject}",
            'status' => 'success',
            'details' => json_encode($jobCompleteData)
        ]);
    }
}

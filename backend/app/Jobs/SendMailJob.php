<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Illuminate\Support\Str;
use App\Jobs\Concerns\LogsJob;
use Illuminate\Support\Facades\Log;

class SendMailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsJob;

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
        $startedAt = now();
        $monotonicStart = microtime(true);

        $queueName = $this->resolveQueueName();

        $toEmail = $this->decryptIfEncrypted($this->toEmail);
        $toName = $this->toName ?: $toEmail;
        $fromAddress = $this->decryptIfEncrypted($this->fromAddress);
        $fromName = $this->fromName ?: $fromAddress;
        $globalCc = $this->decryptIfEncrypted($this->globalCc);
        $globalBcc = $this->decryptIfEncrypted($this->globalBcc);
        $ccAddresses = $this->parseAddresses($globalCc);
        $bccAddresses = $this->parseAddresses($globalBcc);

        // Zentrale Debug-Blacklist-Prüfung
        $validator = app(\App\Services\ReservationValidationService::class);
        if ($validator->isDebugBlacklistedEmail($toEmail)) {
            $emailDomain = strtolower(substr(strrchr($toEmail, '@'), 1));
            $this->logJob(
                static::class,
                'skipped',
                "Adress for domain ($emailDomain) not sent: {$toEmail}",
                [],
                null,
                $startedAt,
                now(),
                $queueName
            );
            Log::info('SendMailJob: Email skipped due to debug blacklist', [
                'to_email' => $toEmail,
                'domain' => $emailDomain
            ]);
            return;
        }

        // Log job start with comprehensive details
        $jobStartData = [
            'to_email' => $toEmail,
            'subject' => $this->subject,
            'has_attachments' => count($this->attachments) > 0,
            'attachment_count' => count($this->attachments),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'global_cc' => $globalCc ? 'configured' : null,
            'global_bcc' => $globalBcc ? 'configured' : null,
            'transport_group_id' => $this->transportGroupId,
            'transport_group_name' => $this->transportGroupName,
            'transport_account_id' => $this->transportAccountId,
            'transport_account_name' => $this->transportAccountName,
        ];
        
        Log::info('SendMailJob: Starting email send process', $jobStartData);

        $this->logJob(
            static::class,
            'started',
            "Email send started for {$toEmail}",
            $jobStartData,
            null,
            $startedAt,
            null,
            $queueName
        );

        $mailerName = 'dynamic_'.md5(json_encode($this->mailerConfig)).'_'.Str::random(6);
        Config::set('mail.mailers.'.$mailerName, $this->mailerConfig);

        Mail::mailer($mailerName)->send([], [], function (Message $message) use ($toEmail, $toName, $fromAddress, $fromName, $ccAddresses, $bccAddresses) {
            $message->to($toEmail, $toName);
            if ($fromAddress) {
                $message->from($fromAddress, $fromName ?: $fromAddress);
            }

            foreach ($ccAddresses as $ccAddress) {
                $message->cc($ccAddress);
            }

            foreach ($bccAddresses as $bccAddress) {
                $message->bcc($bccAddress);
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
            'to_email' => $toEmail,
            'subject' => $this->subject,
            'global_cc' => $globalCc ? 'configured' : null,
            'global_bcc' => $globalBcc ? 'configured' : null,
            'transport_group_id' => $this->transportGroupId,
            'transport_group_name' => $this->transportGroupName,
            'transport_account_id' => $this->transportAccountId,
            'transport_account_name' => $this->transportAccountName,
            'status' => 'success',
            'timestamp' => now()->toISOString()
        ];

        Log::info('SendMailJob: Email sent successfully', $jobCompleteData);

        $runtimeMs = (int) round((microtime(true) - $monotonicStart) * 1000);

        $messageText = "Email sent to {$toEmail}: {$this->subject}";
        if ($this->transportGroupName || $this->transportAccountName) {
            $groupPart = $this->transportGroupName ? "group '{$this->transportGroupName}' (ID: {$this->transportGroupId})" : 'group n/a';
            $accountPart = $this->transportAccountName ? "account '{$this->transportAccountName}' (ID: {$this->transportAccountId})" : 'account n/a';
            $messageText .= " via {$groupPart}, {$accountPart}";
        }

        $this->logJob(
            static::class,
            'success',
            $messageText,
            $jobCompleteData,
            $runtimeMs,
            $startedAt,
            now(),
            $queueName
        );
    }

    private function resolveQueueName(): ?string
    {
        if (!empty($this->queue)) {
            return $this->queue;
        }

        if (method_exists($this, 'queue')) {
            try {
                $queue = $this->queue();
                if (is_string($queue) && $queue !== '') {
                    return $queue;
                }
            } catch (\Throwable $e) {
                // ignore and fall through
            }
        }

        if (!empty($this->connection)) {
            return $this->connection;
        }

        return config('queue.default');
    }

    private function decryptIfEncrypted(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function parseAddresses(?string $addresses): array
    {
        if (! $addresses) {
            return [];
        }

        $list = [];
        foreach (array_filter(array_map('trim', explode(',', $addresses))) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $list[] = $address;
            }
        }

        return $list;
    }
}

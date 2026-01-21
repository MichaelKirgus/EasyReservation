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

class SendMailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly array $mailerConfig,
        private readonly string $toEmail,
        private readonly ?string $toName,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
        private readonly array $attachments = [],
    ) {
    }

    public function handle(): void
    {
        $mailerName = 'dynamic_'.md5(json_encode($this->mailerConfig)).'_'.Str::random(6);
        Config::set('mail.mailers.'.$mailerName, $this->mailerConfig);

        Mail::mailer($mailerName)->send([], [], function (Message $message) {
            $message->to($this->toEmail, $this->toName ?: $this->toEmail);
            if ($this->fromAddress) {
                $message->from($this->fromAddress, $this->fromName ?: $this->fromAddress);
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
    }
}

<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailTransportGroup;
use App\Models\MailGroupAccount;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class MailTransportService
{
    /**
     * Get mailer configuration for a specific account.
     */
    public function getMailerConfig(MailAccount $account): array
    {
        // Build SMTP configuration
        $config = [
            'transport' => 'smtp',
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => $account->encryption,
            'username' => $account->username,
            'password' => $account->password,
            'timeout' => $account->timeout ?: 30,
        ];

        // Add TLS version if specified
        if ($account->tls_version && $account->tls_version !== 'auto') {
            $config['crypto_method'] = $account->tls_version === '1.2'
                ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                : STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        // Handle self-signed certificates
        if ($account->ignore_self_signed) {
            $config['verify_peer'] = false;
            $config['verify_peer_name'] = false;
        }

        return $config;
    }

    /**
     * Get accounts for a transport group based on failover strategy.
     */
    public function getGroupAccounts(MailTransportGroup $group): array
    {
        // Get all active accounts in the group with their priorities
        $groupAccounts = MailGroupAccount::where('group_id', $group->id)
            ->with('account')
            ->whereHas('account', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('priority', 'asc')
            ->get();

        return $groupAccounts->pluck('account')->toArray();
    }

    /**
     * Get next account based on failover strategy.
     */
    public function getNextAccount(MailTransportGroup $group, array $failedAccounts = []): ?MailAccount
    {
        $accounts = $this->getGroupAccounts($group);

        if (empty($accounts)) {
            return null;
        }

        // Filter out failed accounts
        $availableAccounts = array_filter($accounts, function ($account) use ($failedAccounts) {
            return !in_array($account->id, $failedAccounts);
        });

        if (empty($availableAccounts)) {
            // All accounts failed, reset and try all again
            $availableAccounts = $accounts;
        }

        switch ($group->failover_strategy) {
            case 'round_robin':
                return $this->getRoundRobinAccount($group, $availableAccounts);
            case 'random':
                return $this->getRandomAccount($availableAccounts);
            case 'sequential':
            default:
                // Return first available account (priority order)
                return reset($availableAccounts) ?: null;
        }
    }

    /**
     * Get next account using round-robin strategy.
     */
    private function getRoundRobinAccount(MailTransportGroup $group, array $accounts): ?MailAccount
    {
        if (empty($accounts)) {
            return null;
        }

        // Use Redis to track the current index for round-robin
        $key = "mail_group_round_robin:{$group->id}";
        
        try {
            if (Redis::exists($key)) {
                $currentIndex = Redis::get($key);
            } else {
                $currentIndex = 0;
            }

            // Get account at current index (with wrap-around)
            $accountIndex = $currentIndex % count($accounts);
            $account = $accounts[$accountIndex];

            // Increment and save index
            $newIndex = ($currentIndex + 1) % PHP_INT_MAX;
            Redis::set($key, $newIndex);

            return $account;
        } catch (\Exception $e) {
            Log::warning('Redis error in round-robin: ' . $e->getMessage());
            // Fallback to sequential if Redis fails
            return reset($accounts);
        }
    }

    /**
     * Get random account from available accounts.
     */
    private function getRandomAccount(array $accounts): ?MailAccount
    {
        if (empty($accounts)) {
            return null;
        }

        $randomIndex = array_rand($accounts);
        return $accounts[$randomIndex];
    }

    /**
     * Check rate limit for an account.
     */
    public function checkRateLimit(MailAccount $account): bool
    {
        if (!$account->rate_limit_enabled) {
            return true;
        }

        try {
            // Use Redis for rate limiting (configurable via config)
            $driver = config('mail.rate_limiting_driver', 'redis');

            if ($driver === 'database') {
                return $this->checkRateLimitDatabase($account);
            }

            // Redis-based rate limiting
            $prefix = config('mail.rate_limiting_prefix', 'mail_ratelimit:');
            
            if ($account->rate_limit_per_minute) {
                $key = "{$prefix}minute:{$account->id}";
                $current = Redis::get($key);
                
                if ($current && (int)$current >= $account->rate_limit_per_minute) {
                    return false;
                }
                
                Redis::incr($key);
                Redis::expire($key, 60); // Expire after 60 seconds
            }

            if ($account->rate_limit_per_hour) {
                $key = "{$prefix}hour:{$account->id}";
                $current = Redis::get($key);
                
                if ($current && (int)$current >= $account->rate_limit_per_hour) {
                    return false;
                }
                
                Redis::incr($key);
                Redis::expire($key, 3600); // Expire after 3600 seconds
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Rate limit check error: ' . $e->getMessage());
            // Allow sending if rate limiting fails (fail-open)
            return true;
        }
    }

    /**
     * Check rate limit using database instead of Redis.
     */
    private function checkRateLimitDatabase(MailAccount $account): bool
    {
        // Simple database-based rate limiting
        // In production, you might want to use a more sophisticated approach
        
        if ($account->rate_limit_per_minute) {
            $count = \DB::table('job_logs')
                ->where('job', 'SendMailJob')
                ->where('created_at', '>=', now()->subMinute())
                ->whereRaw("JSON_EXTRACT(details, '$.from_address') = ?", [$account->from_address ?: $account->username])
                ->count();

            if ($count >= $account->rate_limit_per_minute) {
                return false;
            }
        }

        if ($account->rate_limit_per_hour) {
            $count = \DB::table('job_logs')
                ->where('job', 'SendMailJob')
                ->where('created_at', '>=', now()->subHour())
                ->whereRaw("JSON_EXTRACT(details, '$.from_address') = ?", [$account->from_address ?: $account->username])
                ->count();

            if ($count >= $account->rate_limit_per_hour) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build mailer config from transport group for use with EmailService methods.
     */
    public function buildMailerConfigFromTransportGroup(int $transportGroupId): ?array
    {
        // Get the transport group with its accounts
        $group = MailTransportGroup::query()->with('accounts')->find($transportGroupId);
        
        if (! $group) {
            return null;
        }

        // Use the first active account from the group as primary
        $account = $group->accounts()->where('is_active', 1)->first();
        
        if (! $account) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $account->host,
            'port' => (int) ($account->port ?? 587),
            'username' => $account->username,
            'password' => $account->password,
            'encryption' => $account->encryption ?: null,
            'timeout' => (int) ($account->timeout ?? 30),
        ];
    }

    /**
     * Send email using a transport group with failover support.
     */
    public function sendWithFailover(
        MailTransportGroup $group,
        string $toEmail,
        ?string $toName,
        string $subject,
        string $body,
        ?string $fromAddress = null,
        ?string $fromName = null,
        array $attachments = [],
        ?string $cc = null,
        ?string $bcc = null
    ): bool {
        $maxRetries = $group->max_retries_per_account ?: 3;
        $failedAccounts = [];
        $lastError = '';

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Get next account based on strategy
            $account = $this->getNextAccount($group, $failedAccounts);

            if (!$account) {
                Log::error('MailTransportService: No available accounts for group ' . $group->id);
                throw new \RuntimeException(__('mail_no_available_accounts'));
            }

            // Check rate limit before attempting to send
            if (!$this->checkRateLimit($account)) {
                $failedAccounts[] = $account->id;
                Log::warning('MailTransportService: Rate limit exceeded for account ' . $account->id);
                continue;
            }

            try {
                // Get mailer config and dispatch job
                $mailerConfig = $this->getMailerConfig($account);

                // Use template-specific from address if set, otherwise use account's from_address
                $effectiveFromAddress = $fromAddress ?: ($account->from_address ?? null);
                $effectiveFromName = $fromName;

                \App\Jobs\SendMailJob::dispatch(
                    $mailerConfig,
                    $toEmail,
                    $toName,
                    $subject,
                    $body,
                    $effectiveFromAddress,
                    $effectiveFromName,
                    $attachments,
                    $cc,
                    $bcc
                );

                return true;
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('MailTransportService: Failed to send via account ' . $account->id, [
                    'error' => $lastError,
                    'attempt' => $attempt + 1
                ]);

                $failedAccounts[] = $account->id;
            }
        }

        throw new \RuntimeException(__('mail_send_failed') . ': ' . $lastError);
    }
}

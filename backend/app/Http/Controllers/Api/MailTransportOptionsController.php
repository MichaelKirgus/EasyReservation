<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailTransportGroup;
use App\Models\MailAccount;
use App\Models\MailGroupAccount;
use Illuminate\Http\Request;

class MailTransportOptionsController extends Controller
{
    /**
     * Display a listing of mail transport options for email templates.
     * Returns both individual accounts and groups with their rate limit info.
     */
    public function index(Request $request)
    {
        // Get all active accounts with their rate limit settings
        $accounts = MailAccount::where('is_active', true)
            ->select([
                'id',
                'name',
                'host',
                'port',
                'encryption',
                'username',
                'auth_method',
                'rate_limit_enabled as account_rate_limit_enabled',
                'rate_limit_per_minute as account_rate_limit_per_minute',
                'rate_limit_per_hour as account_rate_limit_per_hour',
                'from_address',
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'type' => 'account',
                    'name' => $account->name,
                    'host' => $account->host,
                    'port' => $account->port,
                    'encryption' => $account->encryption,
                    'username' => $account->username,
                    'auth_method' => $account->auth_method,
                    'from_address' => $account->from_address,
                    'rate_limit_enabled' => (bool) $account->account_rate_limit_enabled,
                    'rate_limit_per_minute' => $account->account_rate_limit_per_minute,
                    'rate_limit_per_hour' => $account->account_rate_limit_per_hour,
                    'group_id' => null,
                    'group_name' => null,
                ];
            });

        // Get all active groups with their accounts and rate limit info
        $groups = MailTransportGroup::where('is_active', true)
            ->with(['accounts.account' => function ($query) {
                $query->where('is_active', true);
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($group) {
                // Calculate group rate limits (use first account's limits if not set on group)
                $groupRateLimitEnabled = $group->rate_limit_enabled;
                $groupRateLimitPerMinute = $group->rate_limit_per_minute;
                $groupRateLimitPerHour = $group->rate_limit_per_hour;

                // Get accounts in this group
                $accountsInGroup = $group->accounts->map(function ($pivot) {
                    $account = $pivot->account;
                    return [
                        'id' => $account->id,
                        'name' => $account->name,
                        'host' => $account->host,
                        'port' => $account->port,
                        'encryption' => $account->encryption,
                        'username' => $account->username,
                        'auth_method' => $account->auth_method,
                        'from_address' => $account->from_address,
                        'rate_limit_enabled' => (bool) $account->rate_limit_enabled,
                        'rate_limit_per_minute' => $account->rate_limit_per_minute,
                        'rate_limit_per_hour' => $account->rate_limit_per_hour,
                        'priority' => $pivot->priority,
                    ];
                })->values();

                return [
                    'id' => $group->id,
                    'type' => 'group',
                    'name' => $group->name,
                    'description' => $group->description,
                    'failover_strategy' => $group->failover_strategy,
                    'max_retries_per_account' => $group->max_retries_per_account,
                    'rate_limit_enabled' => (bool) $groupRateLimitEnabled,
                    'rate_limit_per_minute' => $groupRateLimitPerMinute,
                    'rate_limit_per_hour' => $groupRateLimitPerHour,
                    'accounts' => $accountsInGroup,
                ];
            });

        return response()->json([
            'accounts' => $accounts,
            'groups' => $groups,
        ]);
    }
}

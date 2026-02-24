<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailTransportGroup;
use App\Models\MailAccount;
use App\Models\MailGroupAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MailGroupController extends Controller
{
    /**
     * Display a listing of mail transport groups.
     */
    public function index(Request $request)
    {
        $query = MailTransportGroup::withCount('accounts');

        // Filter by active status if provided
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by name or description
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sort = $request->get('sort', 'id');
        $direction = $request->get('direction', 'asc');
        $query->orderBy($sort, $direction);

        return response()->json([
            'data' => $query->paginate($request->get('per_page', 15)),
        ]);
    }

    /**
     * Store a newly created mail transport group.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rate_limit_enabled' => 'nullable|boolean',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
            'rate_limit_per_hour' => 'nullable|integer|min:1',
            'failover_strategy' => 'nullable|in:sequential,round_robin,random',
            'max_retries_per_account' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $group = MailTransportGroup::create($validated);

        return response()->json([
            'data' => $group,
            'message' => __('mail_group_created'),
        ], 201);
    }

    /**
     * Display the specified mail transport group.
     */
    public function show(MailTransportGroup $group)
    {
        $group->load(['accounts.account']);

        return response()->json([
            'data' => $group,
        ]);
    }

    /**
     * Update the specified mail transport group.
     */
    public function update(Request $request, MailTransportGroup $group)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rate_limit_enabled' => 'nullable|boolean',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
            'rate_limit_per_hour' => 'nullable|integer|min:1',
            'failover_strategy' => 'nullable|in:sequential,round_robin,random',
            'max_retries_per_account' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        // Use fill+save to guarantee timestamps and events fire, then return fresh state
        $group->fill($validated);
        $group->save();
        $group->refresh();

        return response()->json([
            'data' => $group,
            'message' => __('mail_group_updated'),
        ]);
    }

    /**
     * Remove the specified mail transport group.
     */
    public function destroy(MailTransportGroup $group)
    {
        // Delete pivot table entries first
        MailGroupAccount::where('group_id', $group->id)->delete();

        $deleted = $group->delete();
        if (! $deleted) {
            return response()->json([
                'message' => __('mail_group_deleted'),
                'deleted' => false,
            ], 500);
        }

        return response()->json([
            'message' => __('mail_group_deleted'),
            'deleted' => true,
        ]);
    }

    /**
     * Add an account to a transport group.
     */
    public function addAccount(Request $request, MailTransportGroup $group)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:mail_transport_accounts,id',
            'priority' => 'nullable|integer|min:0',
        ]);

        // Check if account is already in group
        $existing = MailGroupAccount::where('group_id', $group->id)
            ->where('account_id', $validated['account_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('mail_account_already_in_group'),
            ], 422);
        }

        MailGroupAccount::create([
            'group_id' => $group->id,
            'account_id' => $validated['account_id'],
            'priority' => $validated['priority'] ?? 0,
        ]);

        return response()->json([
            'data' => $group->load('accounts'),
            'message' => __('mail_account_added_to_group'),
        ], 201);
    }

    /**
     * Update the priority of an account within a transport group.
     */
    public function updateAccountPriority(Request $request, MailTransportGroup $group, MailAccount $account)
    {
        $validated = $request->validate([
            'priority' => 'required|integer|min:0',
        ]);

        $pivot = MailGroupAccount::where('group_id', $group->id)
            ->where('account_id', $account->id)
            ->first();

        if (! $pivot) {
            return response()->json([
                'error' => __('mail_account_not_in_group'),
            ], 404);
        }

        $pivot->priority = $validated['priority'];
        $pivot->save();

        return response()->json([
            'data' => $group->load('accounts.account'),
            'message' => __('mail_account_priority_updated'),
        ]);
    }

    /**
     * Remove an account from a transport group.
     */
    public function removeAccount(MailTransportGroup $group, MailAccount $account)
    {
        MailGroupAccount::where('group_id', $group->id)
            ->where('account_id', $account->id)
            ->delete();

        return response()->json([
            'message' => __('mail_account_removed_from_group'),
        ]);
    }

    /**
     * Test connection for a mail transport group.
     */
    public function testConnection(MailTransportGroup $group)
    {
        try {
            // Get accounts in the group
            $accounts = MailGroupAccount::where('group_id', $group->id)
                ->with('account')
                ->whereHas('account', function ($query) {
                    $query->where('is_active', true);
                })
                ->orderBy('priority', 'asc')
                ->get();

            if ($accounts->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => __('mail_group_has_no_accounts'),
                ], 422);
            }

            $testAccount = $accounts->first()->account;
            
            // Build SMTP configuration
            $config = [
                'transport' => 'smtp',
                'host' => $testAccount->host,
                'port' => $testAccount->port,
                'encryption' => $testAccount->encryption,
                'username' => $testAccount->username,
                'password' => $testAccount->password,
                'timeout' => $testAccount->timeout ?: 30,
            ];

            // Add TLS version if specified
            if ($testAccount->tls_version && $testAccount->tls_version !== 'auto') {
                $config['crypto_method'] = $testAccount->tls_version === '1.2'
                    ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    : STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            // Handle self-signed certificates
            if ($testAccount->ignore_self_signed) {
                $config['verify_peer'] = false;
                $config['verify_peer_name'] = false;
            }

            // Try to connect using fsockopen for a quick test
            $host = $config['encryption'] === 'ssl' ? "ssl://{$config['host']}" : $config['host'];
            
            $fp = fsockopen($host, $config['port'], $errno, $errstr, 10);
            
            if (!$fp) {
                return response()->json([
                    'success' => false,
                    'message' => "Connection failed: {$errstr} ({$errno})",
                ], 422);
            }

            fclose($fp);

            return response()->json([
                'success' => true,
                'message' => __('mail_connection_tested_successfully'),
            ]);
        } catch (\Exception $e) {
            Log::error('Mail group connection test failed', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

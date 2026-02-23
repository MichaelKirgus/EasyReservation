<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MailAccountController extends Controller
{
    /**
     * Display a listing of mail accounts.
     */
    public function index(Request $request)
    {
        $query = MailAccount::query();

        // Filter by active status if provided
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by name, host, or username
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('host', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
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
     * Store a newly created mail account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'encryption' => 'nullable|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'auth_method' => 'nullable|string|max:50',
            'ignore_self_signed' => 'nullable|boolean',
            'timeout' => 'nullable|integer|min:1|max:300',
            'rate_limit_enabled' => 'nullable|boolean',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
            'rate_limit_per_hour' => 'nullable|integer|min:1',
            'from_address' => 'nullable|email|max:255',
            'reply_to_address' => 'nullable|email|max:255',
            'return_path_address' => 'nullable|email|max:255',
            'tls_version' => 'nullable|in:auto,1.2,1.3',
            'is_active' => 'nullable|boolean',
        ]);

        $account = MailAccount::create($validated);

        return response()->json([
            'data' => $account,
            'message' => __('mail_account_created'),
        ], 201);
    }

    /**
     * Display the specified mail account.
     */
    public function show(MailAccount $account)
    {
        return response()->json($account);
    }

    /**
     * Update the specified mail account.
     */
    public function update(Request $request, MailAccount $account)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'encryption' => 'nullable|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'auth_method' => 'nullable|string|max:50',
            'ignore_self_signed' => 'nullable|boolean',
            'timeout' => 'nullable|integer|min:1|max:300',
            'rate_limit_enabled' => 'nullable|boolean',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
            'rate_limit_per_hour' => 'nullable|integer|min:1',
            'from_address' => 'nullable|email|max:255',
            'reply_to_address' => 'nullable|email|max:255',
            'return_path_address' => 'nullable|email|max:255',
            'tls_version' => 'nullable|in:auto,1.2,1.3',
            'is_active' => 'nullable|boolean',
        ]);

        $account->update($validated);

        return response()->json([
            'data' => $account,
            'message' => __('mail_account_updated'),
        ]);
    }

    /**
     * Remove the specified mail account.
     */
    public function destroy(MailAccount $account)
    {
        $account->delete();

        return response()->json([
            'message' => __('mail_account_deleted'),
        ]);
    }

    /**
     * Test connection for a mail account.
     */
    public function testConnection(MailAccount $account)
    {
        try {
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
            Log::error('Mail account connection test failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

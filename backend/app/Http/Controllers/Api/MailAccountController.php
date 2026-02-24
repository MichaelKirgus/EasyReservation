<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\MailGroupAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $account->fill($validated);
        $account->save();
        $account->refresh();

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
        $id = $account->id;
        try {
            $countBefore = DB::table('mail_transport_accounts')->where('id', $id)->count();
            $modelExistsBefore = MailAccount::where('id', $id)->exists();
            // Remove pivot rows first to avoid FK issues
            $pivotDeleted = MailGroupAccount::where('account_id', $id)->delete();

            // First try eloquent delete on the bound model
            $account->delete();
            $stillExists = MailAccount::where('id', $id)->exists();
            $countAfterEloquent = DB::table('mail_transport_accounts')->where('id', $id)->count();

            // If it still exists, fall back to a direct delete
            $deletedRows = 0;
            if ($stillExists || $countAfterEloquent > 0) {
                $deletedRows = DB::table('mail_transport_accounts')->where('id', $id)->delete();
                $stillExists = MailAccount::where('id', $id)->exists();
                $countAfterEloquent = DB::table('mail_transport_accounts')->where('id', $id)->count();
            }

            $countAfter = $countAfterEloquent;

            if ($stillExists || $countAfter > 0) {
                \Log::warning('MailAccount delete verification failed', [
                    'id' => $id,
                    'deletedRows' => $deletedRows,
                    'stillExists' => $stillExists,
                    'countBefore' => $countBefore,
                    'countAfter' => $countAfter,
                    'modelExistsBefore' => $modelExistsBefore,
                    'pivotDeleted' => $pivotDeleted,
                ]);
                return response()->json([
                    'message' => __('mail_account_deleted'),
                    'deleted' => false,
                    'pivot_deleted' => $pivotDeleted,
                    'still_exists' => $stillExists,
                    'deleted_rows' => $deletedRows,
                    'count_before' => $countBefore,
                    'model_exists_before' => $modelExistsBefore,
                    'count_after' => $countAfter,
                ], 500);
            }

            return response()->json([
                'message' => __('mail_account_deleted'),
                'deleted' => true,
                'pivot_deleted' => $pivotDeleted,
                'deleted_rows' => $deletedRows,
                'count_before' => $countBefore,
                'model_exists_before' => $modelExistsBefore,
                'count_after' => $countAfter,
            ]);
        } catch (\Throwable $e) {
            \Log::error('MailAccount delete exception', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => __('mail_account_deleted'),
                'deleted' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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

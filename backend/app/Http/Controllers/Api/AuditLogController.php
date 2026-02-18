<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            abort(403);
        }
        $logs = AuditLog::latest()->get()->map(function($log) {
            try {
                $log->payload = Crypt::decryptString($log->payload);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Payload is not encrypted or was encrypted with a different key; return as-is
            }
            return $log;
        });
        return response()->json($logs);
    }

    public function clear(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            abort(403);
        }
        AuditLog::truncate();
        return response()->json(['message' => __('audit_log_cleared')]);
    }

    public function count(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            abort(403);
        }
        $count = AuditLog::count();
        return response()->json(['count' => $count]);
    }
}

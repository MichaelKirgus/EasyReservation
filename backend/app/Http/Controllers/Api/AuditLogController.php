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
            $log->payload = Crypt::decryptString($log->payload);
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
        return response()->json(['message' => 'Audit-Log geleert']);
    }
}

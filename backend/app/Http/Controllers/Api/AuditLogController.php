<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
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

        $data = $request->validate([
            'route' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::query();

        if (! empty($data['route'])) {
            $query->where('route', 'like', '%' . trim($data['route']) . '%');
        }

        if (! empty($data['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['date_from'])->startOfDay());
        }

        if (! empty($data['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($data['date_to'])->endOfDay());
        }

        $logs = $query->latest()->get();

        $userIds = $logs
            ->pluck('user_id')
            ->filter(fn ($id) => ! is_null($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $logs = $logs->map(function ($log) use ($usersById) {
            try {
                $log->payload = Crypt::decryptString($log->payload);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Payload is not encrypted or was encrypted with a different key; return as-is
            }

            $resolvedUser = $log->user_id ? $usersById->get((int) $log->user_id) : null;
            if ($resolvedUser) {
                $label = $resolvedUser->name ?: $resolvedUser->email;
                if ($resolvedUser->name && $resolvedUser->email) {
                    $label .= ' (' . $resolvedUser->email . ')';
                }

                $log->user_display = $label;
            } else {
                $log->user_display = null;
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

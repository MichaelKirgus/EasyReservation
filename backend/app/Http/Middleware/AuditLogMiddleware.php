<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Crypt;
use App\Models\AuditLog;

class AuditLogMiddleware
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Audit-Log nur aktiv, wenn AUDIT_LOG nicht FALSE ist
        if (env('AUDIT_LOG', 'TRUE') !== 'FALSE' && str_starts_with($request->path(), 'api/')) {
            $siteToken = $request->header('X-Site-Token') ?? $request->input('site_token') ?? null;
            AuditLog::create([
                'user_id' => auth()->id(),
                'site_token' => $siteToken,
                'route' => $request->path(),
                'method' => $request->method(),
                'payload' => Crypt::encryptString(json_encode($request->all())),
            ]);
        }

        return $response;
    }
}

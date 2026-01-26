<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Crypt;
use App\Models\AuditLog;

class AuditLogMiddleware
{
    public function handle($request, Closure $next)
    {
        \Log::debug('[AuditLogMiddleware] Handle called', [
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => auth()->id(),
        ]);

        $response = $next($request);

        if (env('AUDIT_LOG', 'TRUE') !== 'FALSE') {
            $siteToken = $request->header('X-Site-Token') ?? $request->input('site_token') ?? null;
            $payload = json_encode($request->all());
            try {
                $encryptedPayload = Crypt::encryptString($payload);
                \Log::debug('[AuditLogMiddleware] Creating AuditLog', [
                    'user_id' => auth()->id(),
                    'site_token' => $siteToken,
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'payload' => $payload,
                ]);
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'site_token' => $siteToken,
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'payload' => $encryptedPayload,
                ]);
            } catch (\Throwable $e) {
                \Log::error('[AuditLogMiddleware] Fehler beim Schreiben ins AuditLog', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $response;
    }
}

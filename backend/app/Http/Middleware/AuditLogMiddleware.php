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
            'audit_log_enabled' => config('auditlog.enabled'),
        ]);

        $response = $next($request);

        if (env('AUDIT_LOG', 'TRUE') !== 'FALSE') {
            \Log::debug('[AuditLogMiddleware] Audit log is enabled, creating entry', [
                'user_id' => auth()->id(),
                'route' => $request->path(),
                'method' => $request->method(),
            ]);
            
            $siteToken = $request->header('X-Site-Token') ?? $request->input('site_token') ?? null;
            $payload = json_encode($request->all());
            try {
                \Log::debug('[AuditLogMiddleware] Encrypting payload', [
                    'payload' => $payload,
                ]);
                $encryptedPayload = Crypt::encryptString($payload);
                \Log::debug('[AuditLogMiddleware] Creating AuditLog entry in database', [
                    'user_id' => auth()->id(),
                    'site_token' => $siteToken,
                    'route' => $request->path(),
                    'method' => $request->method(),
                ]);
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'site_token' => $siteToken,
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'payload' => $encryptedPayload,
                ]);
                \Log::debug('[AuditLogMiddleware] Audit log entry created successfully', [
                    'id' => AuditLog::latest('id')->first()?->id,
                ]);
            } catch (\Throwable $e) {
                \Log::error('[AuditLogMiddleware] Fehler beim Schreiben ins AuditLog', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            \Log::debug('[AuditLogMiddleware] Audit log is disabled, skipping entry');
        }

        return $response;
    }
}

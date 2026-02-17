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
            if ($this->isBlacklisted($request)) {
                \Log::debug('[AuditLogMiddleware] Route blacklisted, skipping', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]);
                return $response;
            }
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

    /**
     * Check if the current request matches any blacklisted route pattern.
     * Each blacklist entry can specify 'methods' (array of HTTP methods) and 'path' (regex or prefix).
     */
    private function isBlacklisted($request): bool
    {
        $blacklist = config('auditlog.blacklist', []);
        $method = strtoupper($request->method());
        $path = $request->path();

        foreach ($blacklist as $entry) {
            // Check HTTP method filter
            $methods = array_map('strtoupper', $entry['methods'] ?? ['*']);
            if (!in_array('*', $methods, true) && !in_array($method, $methods, true)) {
                continue;
            }

            // Check path pattern (supports * as wildcard)
            $pattern = $entry['path'] ?? '';
            if ($pattern === '') {
                continue;
            }

            // Convert simple wildcard pattern to regex
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}

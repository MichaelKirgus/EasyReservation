<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure every API request receives a proper JSON response.
 *
 * 1. Sets the Accept header to application/json so that Laravel's exception
 *    handler and validation layer always render JSON (not HTML).
 * 2. Guarantees that JsonResponse instances carry the correct Content-Type,
 *    even when PHP's output buffering is misconfigured.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // Tell Laravel this is a JSON API consumer
        $request->headers->set('Accept', 'application/json');

        /** @var Response $response */
        $response = $next($request);

        // Belt-and-suspenders: enforce correct Content-Type on JSON responses
        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}

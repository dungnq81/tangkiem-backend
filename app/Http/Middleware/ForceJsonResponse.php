<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force all API requests to expect JSON responses and add security headers.
 *
 * This ensures that authentication errors, validation errors,
 * and exceptions always return JSON instead of HTML redirects.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        /** @var Response $response */
        $response = $next($request);

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0'); // Modern browsers, use CSP instead
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Prevent caching of authenticated responses
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}

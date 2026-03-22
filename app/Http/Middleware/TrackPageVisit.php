<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Analytics\AnalyticsCollector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TrackPageVisit Middleware
 *
 * Captures analytics data for API requests.
 * Uses defer() to run tracking AFTER the response is sent to the client,
 * ensuring zero impact on response latency.
 *
 * Only tracks successful GET requests.
 */
class TrackPageVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only track:
        // - GET requests (not POST/PUT/DELETE mutations)
        // - Successful responses (2xx/3xx)
        // - When analytics is enabled
        if (
            $request->method() !== 'GET'
            || $response->getStatusCode() >= 400
            || !config('analytics.enabled', true)
        ) {
            return $response;
        }

        // Defer tracking to after response is sent (non-blocking)
        defer(function () use ($request) {
            app(AnalyticsCollector::class)->track($request);
        });

        return $response;
    }
}

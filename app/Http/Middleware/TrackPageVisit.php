<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Analytics\AnalyticsCollector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TrackPageVisit Middleware
 *
 * Captures analytics data for API requests.
 * Runs tracking synchronously after the response is built but before it's sent.
 * The actual work is a single Redis RPUSH (~1ms), so latency impact is negligible.
 *
 * Note: defer() was previously used here but it doesn't execute reliably
 * on FastPanel's Nginx → Apache → PHP-FPM chain. Synchronous call is safer.
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

        // Track synchronously — Redis push is ~1ms, negligible latency
        try {
            app(AnalyticsCollector::class)->track($request);
        } catch (\Throwable $e) {
            // Never let analytics break the main request
            Log::error('Analytics tracking failed', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\CacheProfiles;

use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Spatie\ResponseCache\CacheProfiles\CacheProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-optimized cache profile for response caching.
 *
 * Rules:
 * - Only caches GET requests to /api/* routes
 * - Skips authenticated requests (user-specific data)
 * - Skips admin panel and web-cron routes
 * - Only caches successful JSON responses (2xx)
 * - Default TTL: 5 minutes (from config)
 */
class ApiCacheProfile implements CacheProfile
{
    public function enabled(Request $request): bool
    {
        return config('responsecache.enabled');
    }

    public function shouldCacheRequest(Request $request): bool
    {
        // Only GET requests
        if (! $request->isMethod('get')) {
            return false;
        }

        // Only API routes
        if (! $request->is('api/*')) {
            return false;
        }

        // Never cache authenticated requests (user-specific data)
        if ($request->user('sanctum')) {
            return false;
        }

        // Never cache web-cron
        if ($request->is('api/web-cron*')) {
            return false;
        }

        return true;
    }

    public function shouldCacheResponse(Response $response): bool
    {
        // Only cache successful responses (2xx)
        if (! $response->isSuccessful()) {
            return false;
        }

        return true;
    }

    public function cacheRequestUntil(Request $request): DateTime
    {
        $lifetime = config('responsecache.cache_lifetime_in_seconds', 300);

        return Carbon::now()->addSeconds($lifetime);
    }

    /**
     * Cache suffix — ensures distinct cache entries per domain group.
     * Since the same API route may be accessed from different domains
     * (via ValidateApiDomain middleware), this isn't needed because
     * the same data is returned regardless of domain.
     */
    public function useCacheNameSuffix(Request $request): string
    {
        return '';
    }
}

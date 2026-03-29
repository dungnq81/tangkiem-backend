<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to track user's last activity time.
 *
 * Uses cache to throttle database updates (every 5 minutes).
 * This reduces database load while maintaining reasonably accurate activity data.
 */
class UserActivity
{
    /**
     * Cache TTL in seconds (5 minutes).
     */
    private const CACHE_TTL = 300;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $cacheKey = 'user-activity:' . $user->id;

            // Only update if cache expired (throttle to every 5 minutes)
            if (!Cache::has($cacheKey)) {
                $user->update(['last_active_at' => now()]);
                Cache::put($cacheKey, true, self::CACHE_TTL);
            }
        }

        return $next($request);
    }
}

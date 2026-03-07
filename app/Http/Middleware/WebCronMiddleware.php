<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\WebCronService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web Cron middleware — registered on admin panel.
 *
 * This middleware is a lightweight pass-through. The actual cron triggering
 * is handled by the heartbeat JS + API endpoints.
 *
 * Kept as a middleware for:
 * - Backward compatibility (registered in AdminPanelProvider)
 * - Future use (could add request-based triggering if needed)
 *
 * All constants and logic now live in WebCronService.
 *
 * @see \App\Services\WebCronService
 * @see resources/views/filament/admin/web-cron-heartbeat.blade.php
 */
class WebCronMiddleware
{
    /**
     * @deprecated Use WebCronService::CACHE_THROTTLE instead.
     */
    public const CACHE_KEY = 'web_cron:last_check';

    /**
     * @deprecated Use WebCronService::getInterval() instead.
     */
    public const CHECK_INTERVAL = 60;

    /**
     * @deprecated Use WebCronService::CACHE_LOCK instead.
     */
    public const LOCK_KEY = 'web_cron:running';

    /**
     * @deprecated Use WebCronService::LOCK_TTL instead.
     */
    public const LOCK_TTL = 1800;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // No-op: heartbeat JS handles web cron triggering.
    }

    /**
     * @deprecated Use WebCronService::generateToken() instead.
     */
    public static function generateToken(): string
    {
        return WebCronService::generateToken();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web Cron middleware — registered on admin panel.
 *
 * Lightweight pass-through. The actual cron triggering
 * is handled by the heartbeat JS + API endpoints.
 *
 * Kept for backward compatibility (registered in AdminPanelProvider).
 *
 * @see \App\Services\WebCron\WebCronManager
 */
class WebCronMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

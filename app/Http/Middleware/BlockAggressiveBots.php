<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to block aggressive/bad bots to save server resources.
 * 
 * Unlike Analytics BotDetector (which flags all bots including Googlebot),
 * this strictly blocks known malicious or heavy-crawling bots that provide
 * no value to the site (e.g., SEO scrapers, AI scrapers without permission).
 */
class BlockAggressiveBots
{
    /**
     * Regex pattern for aggressively bad bots.
     */
    private const BAD_BOT_PATTERN = '/(Bytespider|PetalBot|AhrefsBot|SemrushBot|DotBot|MJ12bot|DataForSeoBot|ClaudeBot|GPTBot|ChatGPT-User|Barkrowler|MegaIndex|Seekport|Baiduspider|YandexBot|BLEXBot|BLEXBot|ZoominfoBot)/i';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = $request->header('X-Forwarded-User-Agent') ?? $request->userAgent() ?? '';

        if ($userAgent !== '' && preg_match(self::BAD_BOT_PATTERN, $userAgent)) {
            // Return 403 Forbidden to stop them before they hit the DB layer
            abort(403, 'Access Denied: Crawler not allowed.');
        }

        return $next($request);
    }
}

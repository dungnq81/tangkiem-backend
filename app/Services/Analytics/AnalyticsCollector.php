<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Analytics\Collector\BotDetector;
use App\Services\Analytics\Collector\GeoIpResolver;
use App\Services\Analytics\Collector\IpAnonymizer;
use App\Services\Analytics\Collector\RedisBuffer;
use App\Services\Analytics\Collector\VisitorParser;
use App\Services\Analytics\Data\VisitData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * AnalyticsCollector — PER-SITE visit tracking (api_domain_id scoped).
 *
 * Thin orchestrator that delegates to focused sub-services:
 * - VisitorParser:  UA + referrer parsing
 * - BotDetector:    bot identification
 * - IpAnonymizer:   privacy-first IP handling
 * - GeoIpResolver:  country-level geolocation (optional, MaxMind GeoLite2)
 * - RedisBuffer:   visit buffering
 *
 * Call flow: TrackPageVisit middleware → AnalyticsCollector::track()
 *
 * Data stored: page_visits → daily_analytics (both have api_domain_id).
 * This is separate from ViewCountService which tracks GLOBAL view counts.
 *
 * Performance: All config reads cached in constructor properties.
 * Goal: minimize overhead per request (this runs on EVERY GET).
 */
class AnalyticsCollector
{
    private readonly bool $enabled;

    /** @var string[] */
    private readonly array $excludePaths;

    /** @var array<string, string> pattern => type */
    private readonly array $pageTypes;

    /** @var string[] IPs to exclude from tracking (admin/internal) */
    private readonly array $excludeIps;

    /** Dedup window in seconds (same session+page within this window = skip) */
    private readonly int $dedupTtl;

    public function __construct(
        private readonly VisitorParser $parser,
        private readonly BotDetector $botDetector,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly GeoIpResolver $geoIp,
        private readonly RedisBuffer $buffer,
    ) {
        $this->enabled = (bool) config('analytics.enabled', true);
        $this->excludePaths = (array) config('analytics.tracking.exclude_paths', []);
        $this->pageTypes = (array) config('analytics.tracking.page_types', []);
        $this->excludeIps = (array) config('analytics.tracking.exclude_ips', []);
        $this->dedupTtl = (int) config('analytics.tracking.dedup_ttl', 300); // 5 minutes
    }

    /**
     * Capture and buffer a page visit from the current request.
     *
     * Filters applied (in order, matches GA behavior):
     * 1. Prefetch/prerender requests → skip (browser speculative loading)
     * 2. Excluded IPs → skip (admin/internal traffic)
     * 3. URL pattern matching → skip if no match
     * 4. Session dedup → skip if same session+page within 5 min (prevents
     *    SSR double-counting and refresh spam)
     */
    public function track(Request $request): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            // Filter 1: Skip prefetch/prerender requests (GA ignores these)
            // Browsers send these headers for speculative resource loading
            if ($this->isPrefetch($request)) {
                return;
            }

            // Filter 2: Skip excluded IPs (admin/internal traffic)
            if ($this->isExcludedIp((string) $request->ip())) {
                return;
            }

            // Filter 3: URL pattern matching
            $pageInfo = $this->resolvePageType($request->path());
            if ($pageInfo === null) {
                return;
            }

            $visit = $this->buildVisitData($request, $pageInfo);

            // Filter 4: Session-based dedup (GA counts 1 pageview per page per session)
            // Prevents: SSR + client double-counting, page refreshes, API retries
            if ($this->isDuplicate($visit->sessionHash, $pageInfo['type'], $pageInfo['slug'])) {
                return;
            }

            $this->buffer->push($visit);
        } catch (\Throwable $e) {
            // Never let analytics tracking break the main request
            Log::error('Analytics tracking failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if request is a browser prefetch/prerender.
     *
     * Browsers send speculative requests with these headers.
     * GA JavaScript doesn't execute during prefetch, so GA skips them.
     * We replicate this behavior server-side.
     */
    private function isPrefetch(Request $request): bool
    {
        // Sec-Purpose: prefetch (modern browsers)
        // Purpose: prefetch (older browsers)
        // X-Purpose: preview (some browsers)
        $secPurpose = $request->header('Sec-Purpose');
        $purpose = $request->header('Purpose');
        $xPurpose = $request->header('X-Purpose');

        return in_array($secPurpose, ['prefetch', 'prerender'], true)
            || in_array($purpose, ['prefetch', 'prerender'], true)
            || $xPurpose === 'preview';
    }

    /**
     * Check if the request IP is in the exclusion list.
     */
    private function isExcludedIp(string $ip): bool
    {
        return in_array($ip, $this->excludeIps, true);
    }

    /**
     * Session-based page dedup using Redis SET with TTL.
     *
     * Same session visiting the same page within the dedup window
     * is counted only once. This matches GA's behavior where:
     * - SSR fetch (Node.js) + client fetch = 1 pageview (not 2)
     * - Page refresh within 5 min = 1 pageview (not 2)
     * - API retry = 1 pageview (not 2)
     *
     * Key format: analytics:dedup:{session_hash}:{page_type}:{slug}
     * TTL: 5 minutes (configurable via analytics.tracking.dedup_ttl)
     *
     * Uses SET NX (only set if not exists) — atomic, O(1), no race conditions.
     */
    private function isDuplicate(string $sessionHash, string $pageType, ?string $slug): bool
    {
        if ($this->dedupTtl <= 0) {
            return false; // Dedup disabled
        }

        $key = "analytics:dedup:{$sessionHash}:{$pageType}:" . ($slug ?? '_');

        // SET NX: returns true if key was set (new visit), false if already exists (dupe)
        $isNew = (bool) Redis::set($key, 1, 'EX', $this->dedupTtl, 'NX');

        return !$isNew; // true = duplicate, false = new visit
    }

    // ═══════════════════════════════════════════════════════════════
    // Visit Data Builder
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build an immutable VisitData DTO from the request.
     *
     * @param  array{type: string, id: int|null, slug: string|null}  $pageInfo
     */
    private function buildVisitData(Request $request, array $pageInfo): VisitData
    {
        // SSR frontends (Next.js) call API from Node.js, which doesn't send
        // browser User-Agent. The frontend should forward the original browser
        // UA via X-Forwarded-User-Agent header. Fall back to standard UA.
        $userAgent = $request->header('X-Forwarded-User-Agent')
            ?? $request->userAgent()
            ?? '';
        $ip = (string) $request->ip();

        $uaInfo = $this->parser->parseUserAgent($userAgent);

        // Use Origin header (CORS) first, then Referer as fallback.
        // CORS fetch requests always send Origin but may omit Referer.
        // Note: $request->header() may return an array when duplicate headers
        // exist (proxies, load balancers). We always need a single string.
        $referrerRaw = $request->header('Origin') ?? $request->header('Referer');
        $referrerUrl = is_array($referrerRaw) ? ($referrerRaw[0] ?? null) : $referrerRaw;

        // Build self-referral domains list: API host + FE domain from ApiDomain
        $apiDomain = $request->attributes->get('api_domain');
        $selfDomains = [$request->getHost()];
        if ($apiDomain?->domain) {
            $selfDomains[] = $apiDomain->domain;
        }

        $referrerInfo = $this->parser->parseReferrer($referrerUrl, $selfDomains);

        return new VisitData(
            visitedAt: now()->toDateTimeString(),
            sessionHash: $this->ipAnonymizer->makeSessionHash($ip, $userAgent),
            pageType: $pageInfo['type'],
            pageId: $pageInfo['id'],
            deviceType: $uaInfo['device_type'],
            browser: $uaInfo['browser'],
            os: $uaInfo['os'],
            referrerType: $referrerInfo['type'],
            referrerDomain: $referrerInfo['domain'],
            isBot: $this->botDetector->isBot($userAgent),
            countryCode: $this->geoIp->resolve($ip),
            apiDomainId: $apiDomain?->id,
            meta: array_filter([
                'page_slug'   => $pageInfo['slug'],
                'ip_hash'     => $this->ipAnonymizer->makeIpHash($ip),
                'ip_address'  => $ip,
                'user_agent'  => mb_substr($userAgent, 0, 512) ?: null,
                'user_id'     => $request->user()?->id,
                'utm_source'  => $this->sanitizeUtm($request->query('utm_source')),
                'utm_medium'  => $this->sanitizeUtm($request->query('utm_medium')),
            ]),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Page Type Resolution
    // ═══════════════════════════════════════════════════════════════

    /**
     * Match request path to a configured page type.
     *
     * @return array{type: string, id: int|null, slug: string|null}|null
     */
    private function resolvePageType(string $path): ?array
    {
        $path = ltrim($path, '/');

        // Check exclusions first (cached array)
        foreach ($this->excludePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return null;
            }
        }

        // Check page types (cached array)
        foreach ($this->pageTypes as $type => $pattern) {
            if (fnmatch($pattern, $path)) {
                $slug = $this->extractSlug($path, $pattern);

                return [
                    'type' => $type,
                    'id'   => null,
                    'slug' => $slug,
                ];
            }
        }

        return null;
    }

    /**
     * Extract slug from the first wildcard match in the pattern.
     */
    private function extractSlug(string $path, string $pattern): ?string
    {
        $pathParts = explode('/', $path);
        $patternParts = explode('/', $pattern);

        foreach ($patternParts as $i => $part) {
            if ($part === '*' && isset($pathParts[$i])) {
                return $pathParts[$i];
            }
        }

        return null;
    }

    /**
     * Sanitize UTM parameter: trim, limit length, nullify empty.
     */
    private function sanitizeUtm(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $value = trim(substr($value, 0, 50));

        return $value !== '' ? $value : null;
    }
}

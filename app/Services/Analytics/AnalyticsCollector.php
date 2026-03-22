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
    }

    /**
     * Capture and buffer a page visit from the current request.
     */
    public function track(Request $request): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $pageInfo = $this->resolvePageType($request->path());

            if ($pageInfo === null) {
                return; // URL doesn't match any tracked pattern
            }

            $visit = $this->buildVisitData($request, $pageInfo);
            $this->buffer->push($visit);
        } catch (\Throwable $e) {
            // Never let analytics tracking break the main request
            Log::warning('Analytics tracking failed: ' . $e->getMessage());
        }
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
        $userAgent = $request->userAgent() ?? '';
        $ip = (string) $request->ip();

        $uaInfo = $this->parser->parseUserAgent($userAgent);
        $referrerInfo = $this->parser->parseReferrer(
            $request->header('Referer'),
            $request->getHost()
        );

        // Extract site context (set by ValidateApiDomain middleware)
        $apiDomain = $request->attributes->get('api_domain');

        return new VisitData(
            visitedAt: now()->toDateTimeString(),
            sessionHash: $this->ipAnonymizer->makeSessionHash($ip, $userAgent),
            pageType: $pageInfo['type'],
            pageId: $pageInfo['id'],
            deviceType: $uaInfo['device_type'],
            browser: $uaInfo['browser'] ?? 'Unknown',
            os: $uaInfo['os'] ?? 'Unknown',
            referrerType: $referrerInfo['type'],
            referrerDomain: $referrerInfo['domain'],
            isBot: $this->botDetector->isBot($userAgent),
            countryCode: $this->geoIp->resolve($ip),
            apiDomainId: $apiDomain?->id,
            meta: array_filter([
                'page_slug'  => $pageInfo['slug'],
                'ip_hash'    => $this->ipAnonymizer->makeIpHash($ip),
                'user_id'    => $request->user()?->id,
                'utm_source' => $this->sanitizeUtm($request->query('utm_source')),
                'utm_medium' => $this->sanitizeUtm($request->query('utm_medium')),
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

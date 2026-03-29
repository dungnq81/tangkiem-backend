<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collector;

/**
 * VisitorParser
 *
 * Lightweight User-Agent parser and referrer classifier.
 * No external dependencies — uses regex patterns for classification.
 *
 * Design choice: We don't need full UA parsing (exact versions, rendering engine).
 * We only need: device_type, browser_family, os_family — which regex handles well.
 *
 * Performance:
 * - Referrer classification uses pre-compiled hashmap for O(1) exact lookups
 * - Config values cached in constructor (no config() calls in hot path)
 */
class VisitorParser
{
    /**
     * Pre-compiled referrer lookup: domain → type (for exact matches).
     *
     * @var array<string, string>
     */
    private readonly array $exactReferrers;

    /**
     * Wildcard referrer patterns: [type, regex].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private readonly array $wildcardReferrers;

    public function __construct()
    {
        [$exact, $wildcards] = $this->compileReferrerRules(
            (array) config('analytics.referrers', [])
        );

        $this->exactReferrers = $exact;
        $this->wildcardReferrers = $wildcards;
    }

    /**
     * Parse User-Agent into device type, browser, and OS.
     *
     * @return array{device_type: string, browser: string|null, os: string|null}
     */
    public function parseUserAgent(?string $userAgent): array
    {
        if (!$userAgent || $userAgent === '') {
            return [
                'device_type' => 'desktop',
                'browser'     => 'Khác',
                'os'          => 'Khác',
            ];
        }

        return [
            'device_type' => $this->detectDeviceType($userAgent),
            'browser'     => $this->detectBrowser($userAgent),
            'os'          => $this->detectOs($userAgent),
        ];
    }

    /**
     * Classify referrer URL into type and domain.
     *
     * @param string|null $referrerUrl   Full Referer/Origin header value
     * @param string|array|null $selfHosts  Current site's host(s) to detect self-referral
     * @return array{domain: string|null, type: string}
     */
    public function parseReferrer(?string $referrerUrl, string|array|null $selfHosts = null): array
    {
        if (!$referrerUrl || $referrerUrl === '') {
            return ['domain' => null, 'type' => 'direct'];
        }

        $host = parse_url($referrerUrl, PHP_URL_HOST);
        if (!$host) {
            return ['domain' => null, 'type' => 'direct'];
        }

        // Normalize: strip www prefix, lowercase
        $domain = strtolower(preg_replace('/^www\./', '', $host));

        // Self-referral = direct (check against all own domains)
        if ($selfHosts) {
            $selfHosts = is_array($selfHosts) ? $selfHosts : [$selfHosts];
            foreach ($selfHosts as $selfHost) {
                $normalized = strtolower(preg_replace('/^www\./', '', $selfHost));
                if ($domain === $normalized || str_ends_with($domain, '.' . $normalized)) {
                    return ['domain' => null, 'type' => 'direct'];
                }
            }
        }

        // Classify by pre-compiled rules (O(1) exact match → O(n) wildcard fallback)
        $type = $this->classifyReferrerDomain($domain);

        return ['domain' => $domain, 'type' => $type];
    }

    // ═══════════════════════════════════════════════════════════════
    // Device Detection
    // ═══════════════════════════════════════════════════════════════

    private function detectDeviceType(string $ua): string
    {
        // Tablet detection (before mobile — tablets also match mobile patterns)
        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|kindle|silk/i', $ua)) {
            return 'tablet';
        }

        // Mobile detection
        if (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|webOS|BlackBerry|Opera Mini|IEMobile|Windows Phone/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    // ═══════════════════════════════════════════════════════════════
    // Browser Detection
    // ═══════════════════════════════════════════════════════════════

    private function detectBrowser(string $ua): string
    {
        // Order matters: check specific/in-app browsers BEFORE generic ones.
        // Most in-app browsers include "Chrome" or "Safari" in their UA.
        // Rule: most specific first → generic engines last.
        $browsers = [
            // ── In-app browsers (must be before Chrome/Safari) ──
            'Facebook'  => '/\bFB[AN_]|FBIOS|FBAV|FBAN\b/i',
            'Instagram' => '/\bInstagram\b/i',
            'Telegram'  => '/\bTelegramBot|TDesktop\b/i',
            'Zalo'      => '/\bZalo\b/i',
            'Pinterest' => '/\bPinterest\b/i',
            'Snapchat'  => '/\bSnapchat\b/i',
            'Line'      => '/\bLine\//i',
            'Twitter'   => '/\bTwitter\b/i',

            // ── Named browsers ──
            'Edge'      => '/Edg(?:e|A|iOS)?\//i',
            'Opera'     => '/(?:OPR|Opera)\//i',
            'Brave'     => '/Brave\//i',
            'Vivaldi'   => '/Vivaldi\//i',
            'Samsung'   => '/SamsungBrowser\//i',
            'UC'        => '/UCBrowser\//i',
            'Coc Coc'   => '/coc_coc/i',
            'Yandex'    => '/YaBrowser\//i',
            'DuckDuckGo'=> '/DuckDuckGo\//i',
            'Whale'     => '/Whale\//i',

            // ── Generic engines (check LAST among real browsers) ──
            'Firefox'   => '/Firefox\//i',
            'Chrome'    => '/Chrome\//i',
            'Safari'    => '/Safari\//i',
            'IE'        => '/(?:MSIE |Trident\/)/i',

            // ── WebViews ──
            'WebView'   => '/\bwv\b|WebView/i',

            // ── Bots & crawlers (detect AFTER real browsers) ──
            'Googlebot'     => '/Googlebot|Google-InspectionTool|Storebot-Google|AdsBot-Google/i',
            'Bingbot'       => '/bingbot|msnbot|BingPreview/i',
            'YandexBot'     => '/YandexBot|YandexImages|YandexMetrika/i',
            'Baiduspider'   => '/Baiduspider|Baidu/i',
            'DuckDuckBot'   => '/DuckDuckBot/i',
            'Facebot'       => '/facebot|facebookexternalhit/i',
            'Twitterbot'    => '/Twitterbot/i',
            'Applebot'      => '/Applebot/i',
            'AhrefsBot'     => '/AhrefsBot/i',
            'SemrushBot'    => '/SemrushBot/i',
            'MJ12bot'       => '/MJ12bot/i',
            'DotBot'        => '/DotBot/i',
            'PetalBot'      => '/PetalBot/i',
            'GPTBot'        => '/GPTBot/i',
            'ClaudeBot'     => '/ClaudeBot|Claude-Web|anthropic-ai/i',
            'ByteSpider'    => '/Bytespider|ByteDance/i',
            'DataForSEO'    => '/DataForSeoBot/i',
            'Sogou'         => '/Sogou/i',
            'CCBot'         => '/CCBot/i',

            // ── HTTP clients & tools ──
            'Node.js'   => '/\b(?:node-fetch|undici|axios|got|next\.js)\b/i',
            'curl'      => '/\bcurl\//i',
            'Wget'      => '/\bWget\//i',
            'Python'    => '/python-requests|python-urllib|aiohttp|httpx|scrapy/i',
            'Java'      => '/\bJava\/|Apache-HttpClient|okhttp/i',
            'Go'        => '/\bGo-http-client|Go\b.*\bpackage http/i',
            'PHP'       => '/\bGuzzleHttp|PHP\//i',
            'Postman'   => '/PostmanRuntime/i',
            'Headless'  => '/HeadlessChrome|PhantomJS|Puppeteer|Playwright/i',
        ];

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Khác';
    }

    // ═══════════════════════════════════════════════════════════════
    // OS Detection
    // ═══════════════════════════════════════════════════════════════

    private function detectOs(string $ua): string
    {
        $systems = [
            'iOS'        => '/(?:iPhone|iPad|iPod).*OS/i',
            'HarmonyOS'  => '/HarmonyOS/i',  // Must be before Android (UA contains both)
            'Android'    => '/Android/i',
            'macOS'      => '/Macintosh|Mac OS X/i',
            'Windows'    => '/Windows/i',
            'Chrome OS'  => '/CrOS/i',
            'FreeBSD'    => '/FreeBSD/i',
            'Linux'      => '/Linux/i',

            // ── Bot/crawler platforms ──
            'Bot'        => '/bot|crawler|spider|slurp|Mediapartners|APIs-Google|AdsBot|feedfetcher/i',
        ];

        foreach ($systems as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Khác';
    }

    // ═══════════════════════════════════════════════════════════════
    // Referrer Classification (pre-compiled)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Classify a referrer domain using pre-compiled rules.
     *
     * 1. O(1) exact match via hashmap
     * 2. O(n) subdomain match via hashmap (strip subdomains progressively)
     * 3. O(n) wildcard regex fallback (only for patterns like 'google.co.*')
     */
    private function classifyReferrerDomain(string $domain): string
    {
        // 1. O(1) exact match
        if (isset($this->exactReferrers[$domain])) {
            return $this->exactReferrers[$domain];
        }

        // 2. Subdomain match: strip subdomains progressively
        // e.g. "l.facebook.com" → check "facebook.com"
        $parts = explode('.', $domain);
        for ($i = 1, $len = count($parts) - 1; $i < $len; $i++) {
            $parent = implode('.', array_slice($parts, $i));
            if (isset($this->exactReferrers[$parent])) {
                return $this->exactReferrers[$parent];
            }
        }

        // 3. Wildcard regex patterns (rare, ~1-2 patterns typically)
        foreach ($this->wildcardReferrers as [$type, $regex]) {
            if (preg_match($regex, $domain)) {
                return $type;
            }
        }

        return 'external';
    }

    /**
     * Pre-compile referrer config into optimized data structures.
     *
     * @param  array<string, string[]>  $config
     * @return array{0: array<string, string>, 1: array<int, array{0: string, 1: string}>}
     */
    private function compileReferrerRules(array $config): array
    {
        $exact = [];
        $wildcards = [];

        foreach ($config as $type => $domains) {
            foreach ($domains as $pattern) {
                if (str_contains($pattern, '*')) {
                    // Wildcard → compile to regex once
                    $regex = '/^' . str_replace(['.', '*'], ['\\.', '[a-z]+'], $pattern) . '$/i';
                    $wildcards[] = [$type, $regex];
                } else {
                    // Exact → hashmap entry
                    $exact[$pattern] = $type;
                }
            }
        }

        return [$exact, $wildcards];
    }
}

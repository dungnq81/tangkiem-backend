<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics Module — Architecture Overview
|--------------------------------------------------------------------------
|
| Data scoping strategy:
|
| ┌─────────────────────────────┬────────────────┬──────────────────────────┐
| │ Feature                     │ Scope          │ Storage                  │
| ├─────────────────────────────┼────────────────┼──────────────────────────┤
| │ View counts (stories/chap.) │ GLOBAL         │ stories.view_count       │
| │                             │                │ chapters.view_count      │
| ├─────────────────────────────┼────────────────┼──────────────────────────┤
| │ Page analytics              │ PER-SITE       │ page_visits              │
| │                             │ (api_domain_id)│ daily_analytics          │
| ├─────────────────────────────┼────────────────┼──────────────────────────┤
| │ Bookmarks / History / Rats  │ PER-SITE       │ bookmarks                │
| │                             │ (api_domain_id)│ reading_history / ratings │
| └─────────────────────────────┴────────────────┴──────────────────────────┘
|
| View counts are intentionally GLOBAL because:
| - Rankings need aggregated counts across all FE domains
| - Per-site view data is available via daily_analytics table
| - ViewCountService (Redis-buffered) → stories/chapters tables directly
|
| Per-site analytics flow:
| TrackPageVisit middleware → AnalyticsCollector → RedisBuffer
| → analytics:aggregate command → page_visits → daily_analytics
| Each record carries api_domain_id from ValidateApiDomain middleware.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, TrackPageVisit middleware does nothing.
    | View counts (ViewCountService) are NOT affected by this switch.
    |
    */

    'enabled' => env('ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Tracking — What Gets Tracked
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        // Page types mapped from URL patterns.
        // Order matters: first match wins.
        // * = dynamic segment (slug/id, captured for page_id lookup)
        'page_types' => [
            'chapter'  => 'api/v1/stories/*/chapters/*',
            'story'    => 'api/v1/stories/*',
            'category' => 'api/v1/categories/*',
            'search'   => 'api/v1/search*',
            'ranking'  => 'api/v1/rankings/*',
            'author'   => 'api/v1/authors/*',
        ],

        // URLs excluded from tracking (glob patterns)
        'exclude_paths' => [
            'api/web-cron*',
            'api/scrape-run*',
            'api/v1/auth/*',
            'api/v1/user/*',
            'api/v1/sitemap*',
            'up',
        ],

        // IPs excluded from tracking (admin/internal traffic).
        // Equivalent to GA's "internal traffic" filter.
        // Add your admin/office IPs here to exclude from analytics.
        'exclude_ips' => array_filter(explode(',', env('ANALYTICS_EXCLUDE_IPS', ''))),

        // Session dedup window (seconds).
        // Same session visiting the same page within this window = 1 pageview.
        // Prevents: SSR double-counting, page refreshes, API retries.
        // Set to 0 to disable dedup.
        // GA equivalent: GA counts 1 pageview per page per session.
        'dedup_ttl' => (int) env('ANALYTICS_DEDUP_TTL', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | page_visits: Raw per-request data. Large table, cleaned up daily.
    | daily_analytics: Aggregated daily summaries. Much smaller, kept longer.
    |
    | After cleanup, data is permanently deleted. Make sure the aggregated
    | retention is >= raw retention, otherwise you lose un-aggregated data.
    |
    */

    'retention' => [
        'raw_days'        => (int) env('ANALYTICS_RAW_RETENTION', 30),        // page_visits: 30 days
        'aggregated_days' => (int) env('ANALYTICS_AGGREGATED_RETENTION', 180), // daily_analytics: 6 months
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Buffer
    |--------------------------------------------------------------------------
    |
    | Visits are buffered in Redis to avoid DB writes per request.
    | The analytics:aggregate command flushes this buffer periodically.
    |
    */

    'buffer' => [
        'key'        => 'analytics:visits',
        'max_size'   => 10000,   // Safety cap — drop entries if buffer exceeds
        'batch_size' => 500,     // Max rows per batch insert during aggregation
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | IP addresses are never stored in plain text.
    | Hash algorithm: xxh3 (fast, non-cryptographic — hardcoded in IpAnonymizer).
    |
    | session_hash = xxh3(anonymized_ip + user_agent) → unique visitor per day
    | ip_hash      = xxh3(anonymized_ip + daily_salt) → privacy-safe dedup
    |
    */

    'privacy' => [
        'anonymize_ip' => true,    // Strip last octet (IPv4) / last 5 groups (IPv6)
        'daily_salt'   => true,    // Rotate hash salt daily (prevents long-term tracking)
    ],

    /*
    |--------------------------------------------------------------------------
    | Referrer Classification
    |--------------------------------------------------------------------------
    |
    | Domains classified into types for traffic source analysis.
    | 'direct' = no referrer or same-site.
    | Anything not listed = 'external'.
    |
    */

    'referrers' => [
        'search' => [
            'google.com', 'google.com.vn', 'google.co.*',
            'bing.com', 'yahoo.com', 'duckduckgo.com',
            'baidu.com', 'yandex.com', 'yandex.ru',
            'ecosia.org', 'search.brave.com', 'coccoc.com',
        ],
        'social' => [
            'facebook.com', 'fb.com', 'fb.me', 'l.facebook.com',
            't.co', 'twitter.com', 'x.com',
            'instagram.com',
            'tiktok.com', 'vm.tiktok.com',
            'youtube.com', 'youtu.be',
            'reddit.com',
            'zalo.me', 'chat.zalo.me',
            'threads.net',
            'pinterest.com',
            'linkedin.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot Detection
    |--------------------------------------------------------------------------
    |
    | User-Agent substrings that identify known bots/crawlers.
    | Matched case-insensitively. Bot visits are tracked but flagged (is_bot=1).
    |
    */

    'bot_patterns' => [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
        'Googlebot', 'bingbot', 'Baiduspider', 'YandexBot',
        'DuckDuckBot', 'facebot', 'ia_archiver', 'Sogou',
        'AhrefsBot', 'SemrushBot', 'DotBot', 'MJ12bot',
        'PetalBot', 'Bytespider', 'GPTBot', 'ClaudeBot',
        'CCBot', 'DataForSeoBot', 'Applebot', 'archive.org_bot',
        'wget', 'curl', 'python-requests', 'scrapy',
        'headless', 'phantomjs', 'selenium',
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Geolocation — MaxMind GeoLite2 (Optional)
    |--------------------------------------------------------------------------
    |
    | Country-level geolocation from IP address.
    | Used by: GeoIpResolver → AnalyticsCollector → page_visits.country_code
    |
    | Setup:
    | 1. Register free at https://dev.maxmind.com/geoip/geolite2-free-geolocation-data
    | 2. Download GeoLite2-Country.mmdb
    | 3. Place in storage/app/geoip/GeoLite2-Country.mmdb (or custom path via env)
    | 4. Set ANALYTICS_GEO_ENABLED=true in .env
    |
    | When disabled: country_code = null in page_visits (graceful degradation).
    |
    */

    'geolocation' => [
        'enabled'  => env('ANALYTICS_GEO_ENABLED', false),
        'database' => env('ANALYTICS_GEO_DATABASE', storage_path('app/geoip/GeoLite2-Country.mmdb')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Analytics 4 — Optional Integration
    |--------------------------------------------------------------------------
    |
    | Import aggregated data from GA4 into daily_analytics.
    | Requires:
    | 1. A GA4 property (get ID from Google Analytics admin)
    | 2. A Google Cloud service account JSON key file
    |    → Google Cloud Console → IAM → Service Account → Create JSON key
    |    → Save to storage/app/ga-credentials.json (or custom path via env)
    |
    | Usage: php artisan analytics:import-ga
    |
    */

    'google_analytics' => [
        'enabled'     => env('GA_ENABLED', false),
        'property_id' => env('GA_PROPERTY_ID'),
        'credentials' => env('GA_CREDENTIALS_PATH', storage_path('app/ga-credentials.json')),
    ],

];

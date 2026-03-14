<?php

return [
    /*
     * Determine if the response cache middleware should be enabled.
     * Disable in .env: RESPONSE_CACHE_ENABLED=false
     */
    'enabled' => env('RESPONSE_CACHE_ENABLED', true),

    /*
     * Custom cache profile for API routes:
     * - Only caches GET requests to /api/* endpoints
     * - Skips authenticated users (user-specific data)
     * - Skips admin panel entirely
     * - Default TTL: 5 minutes (overridable per-route via middleware param)
     */
    'cache_profile' => App\Http\CacheProfiles\ApiCacheProfile::class,

    /*
     * Header to bypass cache (for debugging/monitoring).
     */
    'cache_bypass_header' => [
        'name' => env('CACHE_BYPASS_HEADER_NAME', null),
        'value' => env('CACHE_BYPASS_HEADER_VALUE', null),
    ],

    /*
     * Default cache lifetime: 5 minutes.
     * Per-route TTLs are set via middleware: cacheResponse:300
     */
    'cache_lifetime_in_seconds' => (int) env('RESPONSE_CACHE_LIFETIME', 300),

    /*
     * Add debug header with cache timestamp (only in debug mode).
     */
    'add_cache_time_header' => env('APP_DEBUG', false),

    'cache_time_header_name' => env('RESPONSE_CACHE_HEADER_NAME', 'X-Response-Cache'),

    /*
     * Add cache age header (how old the cached response is).
     * Only works when add_cache_time_header is also active.
     */
    'add_cache_age_header' => env('RESPONSE_CACHE_AGE_HEADER', false),

    'cache_age_header_name' => env('RESPONSE_CACHE_AGE_HEADER_NAME', 'X-Response-Cache-Age'),

    /*
     * Cache store: uses the same store as the app.
     * Follows the main CACHE_STORE setting by default.
     */
    'cache_store' => env('RESPONSE_CACHE_DRIVER', env('CACHE_STORE', 'file')),

    /*
     * Response replacers (csrf token replacement for web pages).
     * Not needed for pure JSON API responses, but kept for safety.
     */
    'replacers' => [
        \Spatie\ResponseCache\Replacers\CsrfTokenReplacer::class,
    ],

    /*
     * Cache tags (only works with tag-supported stores like Redis).
     * Empty = no tags (works with file store).
     */
    'cache_tag' => '',

    /*
     * Request hasher — generates unique cache key per request.
     */
    'hasher' => \Spatie\ResponseCache\Hasher\DefaultHasher::class,

    /*
     * Response serializer.
     */
    'serializer' => \Spatie\ResponseCache\Serializers\DefaultSerializer::class,
];

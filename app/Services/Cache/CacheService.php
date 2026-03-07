<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CacheService - Unified caching layer for the application.
 *
 * Works with both file and Redis cache drivers.
 * Switch via .env: CACHE_STORE=file (default) or CACHE_STORE=redis
 *
 * Usage:
 *   $cacheService = app(CacheService::class);
 *   $data = $cacheService->remember('key', 3600, fn() => expensiveOperation());
 */
class CacheService
{
    /**
     * Default TTL in seconds (1 hour).
     */
    protected int $defaultTtl = 3600;

    /**
     * Cache key prefix for namespacing.
     */
    protected string $prefix = 'tk_';

    /**
     * Check if Redis is the active cache driver.
     */
    public function isRedis(): bool
    {
        return config('cache.default') === 'redis';
    }

    /**
     * Get the current cache driver name.
     */
    public function getDriver(): string
    {
        return config('cache.default', 'file');
    }

    /**
     * Get cached value or execute callback and cache the result.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $fullKey = $this->prefixKey($key);

        return Cache::remember($fullKey, $ttl, $callback);
    }

    /**
     * Get cached value or execute callback and cache forever.
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        $fullKey = $this->prefixKey($key);

        return Cache::rememberForever($fullKey, $callback);
    }

    /**
     * Get a cached value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->prefixKey($key), $default);
    }

    /**
     * Store a value in the cache.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;

        return Cache::put($this->prefixKey($key), $value, $ttl);
    }

    /**
     * Store a value in the cache forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        return Cache::forever($this->prefixKey($key), $value);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return Cache::forget($this->prefixKey($key));
    }

    /**
     * Remove multiple items by pattern (Redis only).
     * Falls back to individual forget for file cache.
     */
    public function forgetByPattern(string $pattern, array $knownKeys = []): int
    {
        $count = 0;

        if ($this->isRedis()) {
            try {
                $redis = Cache::getRedis();
                $fullPattern = config('cache.prefix') . $this->prefix . $pattern;
                $keys = $redis->keys($fullPattern);

                foreach ($keys as $key) {
                    $redis->del($key);
                    $count++;
                }
            } catch (\Exception $e) {
                Log::warning("Redis forgetByPattern failed: {$e->getMessage()}");
                // Fallback to known keys
                foreach ($knownKeys as $key) {
                    if ($this->forget($key)) {
                        $count++;
                    }
                }
            }
        } else {
            // File cache: forget known keys only
            foreach ($knownKeys as $key) {
                if ($this->forget($key)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Check if a key exists in the cache.
     */
    public function has(string $key): bool
    {
        return Cache::has($this->prefixKey($key));
    }

    /**
     * Increment a cached value (useful for counters).
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return Cache::increment($this->prefixKey($key), $value);
    }

    /**
     * Decrement a cached value.
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return Cache::decrement($this->prefixKey($key), $value);
    }

    /**
     * Clear all cache (use with caution).
     */
    public function flush(): bool
    {
        return Cache::flush();
    }

    /**
     * Add prefix to cache key.
     */
    protected function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Get cache statistics (Redis only).
     */
    public function getStats(): array
    {
        if (!$this->isRedis()) {
            return [
                'driver' => 'file',
                'message' => 'Stats not available for file driver',
            ];
        }

        try {
            $redis = Cache::getRedis();
            $info = $redis->info();

            return [
                'driver' => 'redis',
                'version' => $info['redis_version'] ?? 'unknown',
                'uptime_days' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 2),
                'connected_clients' => $info['connected_clients'] ?? 0,
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'total_keys' => $redis->dbSize(),
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate(
                    $info['keyspace_hits'] ?? 0,
                    $info['keyspace_misses'] ?? 0
                ),
            ];
        } catch (\Exception $e) {
            return [
                'driver' => 'redis',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate cache hit rate percentage.
     */
    protected function calculateHitRate(int $hits, int $misses): string
    {
        $total = $hits + $misses;
        if ($total === 0) {
            return '0%';
        }

        return round(($hits / $total) * 100, 2) . '%';
    }
}

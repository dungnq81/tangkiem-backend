<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Cache navigation badge counts to avoid running COUNT(*) on every page load.
 *
 * Usage: Override getNavigationBadge() in your Resource:
 *   return static::getCachedCount();
 *
 * Cache is per-model, auto-expires every 5 minutes.
 * Can be busted via: Cache::forget("nav_badge:{ModelClass}")
 */
trait HasCachedNavigationBadge
{
    /**
     * Get cached count for this resource's model.
     * TTL: 5 minutes (300 seconds).
     */
    protected static function getCachedCount(): string
    {
        $modelClass = static::getModel();
        $cacheKey = 'nav_badge:' . class_basename($modelClass);

        return (string) Cache::remember(
            $cacheKey,
            300, // 5 minutes
            fn () => $modelClass::count()
        );
    }

    /**
     * Cache a custom badge value with a suffix key.
     * For resources that use custom queries (e.g., active-only counts).
     */
    protected static function getCachedValue(string $suffix, callable $callback): string
    {
        $modelClass = static::getModel();
        $cacheKey = 'nav_badge:' . class_basename($modelClass) . ':' . $suffix;

        return (string) Cache::remember($cacheKey, 300, $callback);
    }

    /**
     * Bust the cache for this resource's badge.
     * Call from observers/events after create/delete.
     */
    public static function bustBadgeCache(): void
    {
        $modelClass = static::getModel();
        $prefix = 'nav_badge:' . class_basename($modelClass);
        Cache::forget($prefix);
    }
}

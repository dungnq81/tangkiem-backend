<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\Story;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RankingService - Handles story rankings with caching.
 *
 * Rankings are calculated and cached, refreshed periodically via cron job.
 * Redis provides faster sorted sets for rankings.
 */
class RankingService
{
    protected CacheService $cache;
    protected StoryCacheService $storyCache;

    // TTL for rankings cache
    public const TTL_RANKINGS = 1800; // 30 minutes

    public function __construct(CacheService $cache, StoryCacheService $storyCache)
    {
        $this->cache = $cache;
        $this->storyCache = $storyCache;
    }

    /**
     * Get daily top stories by views.
     */
    public function getDailyTop(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "rankings:daily:{$limit}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count_day')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get weekly top stories by views.
     */
    public function getWeeklyTop(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "rankings:weekly:{$limit}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count_week')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get monthly top stories by views.
     */
    public function getMonthlyTop(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "rankings:monthly:{$limit}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count_month')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get all-time top stories by views.
     */
    public function getAllTimeTop(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "rankings:alltime:{$limit}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get top rated stories.
     */
    public function getTopRated(int $limit = 20, int $minRatings = 10): Collection
    {
        return $this->cache->remember(
            "rankings:rated:{$limit}:{$minRatings}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->where('rating_count', '>=', $minRatings)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('rating')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get trending stories (combination of recent views + growth).
     */
    public function getTrending(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "rankings:trending:{$limit}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                // Score = daily views * 3 + weekly views
                ->orderByRaw('(view_count_day * 3 + view_count_week) DESC')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get new releases (recently published with good initial reception).
     */
    public function getNewReleases(int $limit = 20, int $daysAgo = 30): Collection
    {
        return $this->cache->remember(
            "rankings:new:{$limit}:{$daysAgo}",
            self::TTL_RANKINGS,
            fn () => Story::query()
                ->where('is_published', true)
                ->where('published_at', '>=', now()->subDays($daysAgo))
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Refresh all ranking caches.
     */
    public function refreshRankings(): void
    {
        $knownKeys = [
            'rankings:daily:20',
            'rankings:daily:10',
            'rankings:weekly:20',
            'rankings:weekly:10',
            'rankings:monthly:20',
            'rankings:monthly:10',
            'rankings:alltime:20',
            'rankings:alltime:10',
            'rankings:rated:20:10',
            'rankings:trending:20',
            'rankings:new:20:30',
        ];

        $this->cache->forgetByPattern('rankings:*', $knownKeys);

        // Pre-warm common rankings
        $this->getDailyTop(20);
        $this->getWeeklyTop(20);
        $this->getMonthlyTop(20);
        $this->getTrending(20);
    }
}

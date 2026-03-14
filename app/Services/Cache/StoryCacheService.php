<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\Story;
use Illuminate\Database\Eloquent\Collection;

/**
 * StoryCacheService - Cache layer for Story-related data.
 *
 * TTL Reference:
 * - Featured/Hot stories: 10 minutes (frequently updated)
 * - Story details: 30 minutes
 * - Story list: 15 minutes
 * - Static data: 1 hour
 */
class StoryCacheService
{
    protected CacheService $cache;

    // TTL constants (in seconds)
    public const TTL_SHORT = 600;      // 10 minutes
    public const TTL_MEDIUM = 1800;    // 30 minutes
    public const TTL_LONG = 3600;      // 1 hour
    public const TTL_DAY = 86400;      // 24 hours

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get featured stories.
     */
    public function getFeaturedStories(int $limit = 10): Collection
    {
        return $this->cache->remember(
            "stories:featured:{$limit}",
            self::TTL_SHORT,
            fn () => Story::query()
                ->where('is_featured', true)
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get hot stories.
     */
    public function getHotStories(int $limit = 10): Collection
    {
        return $this->cache->remember(
            "stories:hot:{$limit}",
            self::TTL_SHORT,
            fn () => Story::query()
                ->where('is_hot', true)
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('view_count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get latest stories.
     */
    public function getLatestStories(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "stories:latest:{$limit}",
            self::TTL_SHORT,
            fn () => Story::query()
                ->where('is_published', true)
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('last_chapter_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get completed stories.
     */
    public function getCompletedStories(int $limit = 20): Collection
    {
        return $this->cache->remember(
            "stories:completed:{$limit}",
            self::TTL_MEDIUM,
            fn () => Story::query()
                ->where('is_published', true)
                ->where('status', 'completed')
                ->with(['author', 'primaryCategory'])
                ->orderByDesc('rating')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get story by ID with relationships.
     */
    public function getStoryById(int $id): ?Story
    {
        return $this->cache->remember(
            "story:{$id}",
            self::TTL_MEDIUM,
            fn () => Story::with([
                'author',
                'primaryCategory',
                'categories',
                'tags',
            ])->find($id)
        );
    }

    /**
     * Get story by slug.
     */
    public function getStoryBySlug(string $slug): ?Story
    {
        return $this->cache->remember(
            "story:slug:{$slug}",
            self::TTL_MEDIUM,
            fn () => Story::with([
                'author',
                'primaryCategory',
                'categories',
                'tags',
            ])->where('slug', $slug)->first()
        );
    }

    /**
     * Get stories by category.
     */
    public function getStoriesByCategory(int $categoryId, int $page = 1, int $perPage = 20): array
    {
        return $this->cache->remember(
            "stories:category:{$categoryId}:page:{$page}",
            self::TTL_SHORT,
            function () use ($categoryId, $perPage) {
                $paginator = Story::query()
                    ->where('primary_category_id', $categoryId)
                    ->where('is_published', true)
                    ->with(['author'])
                    ->orderByDesc('last_chapter_at')
                    ->paginate($perPage);

                return [
                    'items' => $paginator->items(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ];
            }
        );
    }

    /**
     * Clear story cache when updated.
     */
    public function clearStoryCache(Story $story): void
    {
        $this->cache->forget("story:{$story->id}");
        $this->cache->forget("story:slug:{$story->slug}");

        // Clear list caches
        $this->clearListCaches();
    }

    /**
     * Clear all story list caches.
     */
    public function clearListCaches(): void
    {
        $knownKeys = [
            'stories:featured:10',
            'stories:featured:5',
            'stories:hot:10',
            'stories:hot:5',
            'stories:latest:20',
            'stories:latest:10',
            'stories:completed:20',
            'stories:completed:10',
        ];

        $this->cache->forgetByPattern('stories:*', $knownKeys);
    }
}

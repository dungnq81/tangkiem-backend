<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\Author;
use App\Models\Chapter;
use App\Models\Story;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ViewCountService
 *
 * Manages view counts with cache buffering.
 * - Increment views: stored in cache (fast)
 * - Periodic sync: push from cache to database (batch)
 *
 * Works with both file cache (local) and redis (production).
 * View counts are always stored in stories/chapters tables in DB.
 */
class ViewCountService
{
    /**
     * Cache key prefixes
     */
    private const CACHE_PREFIX_CHAPTER = 'views:chapter:';
    private const CACHE_PREFIX_STORY = 'views:story:';
    private const CACHE_PREFIX_STORY_DAILY = 'views:story:daily:';
    private const CACHE_PREFIX_STORY_WEEKLY = 'views:story:weekly:';
    private const CACHE_PREFIX_STORY_MONTHLY = 'views:story:monthly:';

    /**
     * Cache key to track IDs that have views (used for batch sync)
     */
    private const CACHE_TRACKED_CHAPTERS = 'views:tracked:chapters';
    private const CACHE_TRACKED_STORIES = 'views:tracked:stories';

    /**
     * Cache TTL (keep counter in cache for max 24h)
     */
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * View deduplication window (same visitor + chapter = 1 view within this window).
     */
    private const DEDUP_TTL = 1800; // 30 minutes
    private const CACHE_PREFIX_SEEN = 'views:seen:';

    protected CacheService $cache;

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Increment view count for a chapter (with deduplication).
     * Called from API when user reads a chapter.
     *
     * @param int         $chapterId Chapter ID
     * @param int|null    $storyId   Story ID (if null, will be fetched from chapter)
     * @param string|null $visitorId Unique visitor identifier (user ID or IP) for dedup
     */
    public function incrementChapterView(int $chapterId, ?int $storyId = null, ?string $visitorId = null): void
    {
        // Dedup: skip if the same visitor viewed this chapter recently
        if ($visitorId && $this->hasRecentView($chapterId, $visitorId)) {
            return;
        }

        // Mark as viewed for dedup window
        if ($visitorId) {
            $this->markAsViewed($chapterId, $visitorId);
        }

        // Increment chapter views
        $this->incrementCache(self::CACHE_PREFIX_CHAPTER . $chapterId);
        $this->trackId(self::CACHE_TRACKED_CHAPTERS, $chapterId);

        // If storyId not provided, get it from chapter
        if (!$storyId) {
            $storyId = Chapter::query()->where('id', $chapterId)->value('story_id');
        }

        // Increment views for story
        if ($storyId) {
            $this->incrementStoryView($storyId);
        }
    }

    /**
     * Increment view count for a story.
     * Can be called directly or via incrementChapterView.
     */
    public function incrementStoryView(int $storyId): void
    {
        // Total views
        $this->incrementCache(self::CACHE_PREFIX_STORY . $storyId);

        // Daily views
        $this->incrementCache(self::CACHE_PREFIX_STORY_DAILY . $storyId);

        // Weekly views
        $this->incrementCache(self::CACHE_PREFIX_STORY_WEEKLY . $storyId);

        // Monthly views
        $this->incrementCache(self::CACHE_PREFIX_STORY_MONTHLY . $storyId);

        // Track story ID for batch sync
        $this->trackId(self::CACHE_TRACKED_STORIES, $storyId);
    }

    /**
     * Get pending chapter views from cache (not yet synced).
     */
    public function getChapterPendingViews(int $chapterId): int
    {
        return (int) Cache::get(self::CACHE_PREFIX_CHAPTER . $chapterId, 0);
    }

    /**
     * Get pending story views from cache (not yet synced).
     */
    public function getStoryPendingViews(int $storyId): int
    {
        return (int) Cache::get(self::CACHE_PREFIX_STORY . $storyId, 0);
    }

    /**
     * Sync view counts from cache to database.
     * Run by scheduled command every 5 minutes.
     *
     * Uses batch CASE/WHEN UPDATE to sync all dirty records in 1 query per table,
     * instead of N individual UPDATE queries. This reduces DB round-trips from
     * O(chapters + stories) to O(2) regardless of volume.
     */
    public function syncToDatabase(): array
    {
        $stats = [
            'chapters' => 0,
            'stories' => 0,
            'total_views' => 0,
        ];

        try {
            // ─── Phase 1: Collect all pending views from cache ────────────
            $chapterIds = $this->getTrackedIds(self::CACHE_TRACKED_CHAPTERS);
            $chapterUpdates = []; // [id => views]

            foreach ($chapterIds as $chapterId) {
                $views = $this->getAndResetCache(self::CACHE_PREFIX_CHAPTER . $chapterId);
                if ($views > 0) {
                    $chapterUpdates[$chapterId] = $views;
                    $stats['total_views'] += $views;
                }
            }
            $this->clearTracked(self::CACHE_TRACKED_CHAPTERS);

            $storyIds = $this->getTrackedIds(self::CACHE_TRACKED_STORIES);
            $storyUpdates = []; // [id => [total, daily, weekly, monthly]]

            foreach ($storyIds as $storyId) {
                $views = $this->getAndResetCache(self::CACHE_PREFIX_STORY . $storyId);
                $viewsDaily = $this->getAndResetCache(self::CACHE_PREFIX_STORY_DAILY . $storyId);
                $viewsWeekly = $this->getAndResetCache(self::CACHE_PREFIX_STORY_WEEKLY . $storyId);
                $viewsMonthly = $this->getAndResetCache(self::CACHE_PREFIX_STORY_MONTHLY . $storyId);

                if ($views > 0) {
                    $storyUpdates[$storyId] = [
                        'total'   => $views,
                        'daily'   => $viewsDaily,
                        'weekly'  => $viewsWeekly,
                        'monthly' => $viewsMonthly,
                    ];
                }
            }
            $this->clearTracked(self::CACHE_TRACKED_STORIES);

            // ─── Phase 2: Batch UPDATE via CASE/WHEN (1 query per table) ─
            if (!empty($chapterUpdates)) {
                $this->batchUpdateChapterViews($chapterUpdates);
                $stats['chapters'] = count($chapterUpdates);
            }

            if (!empty($storyUpdates)) {
                $this->batchUpdateStoryViews($storyUpdates);
                $stats['stories'] = count($storyUpdates);
            }

            // ─── Phase 3: Update author denormalized stats ───────────────
            if (!empty($storyUpdates)) {
                $this->updateAuthorStats(array_map('intval', array_keys($storyUpdates)));
            }
        } catch (\Exception $e) {
            Log::error("ViewCountService sync failed: {$e->getMessage()}");
        }

        return $stats;
    }

    /**
     * Batch update chapter view counts in a single query.
     *
     * Generates: UPDATE chapters SET view_count = view_count + CASE id
     *            WHEN 1 THEN 5 WHEN 2 THEN 3 ... END WHERE id IN (1,2,...)
     *
     * @param array<int, int> $updates [chapterId => viewCount]
     */
    private function batchUpdateChapterViews(array $updates): void
    {
        $cases = [];
        $ids = [];
        $bindings = [];

        foreach ($updates as $id => $views) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $views;
            $ids[] = $id;
        }

        $caseSql = implode(' ', $cases);
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);

        $prefix = DB::getTablePrefix();

        DB::statement(
            "UPDATE {$prefix}chapters
             SET view_count = view_count + CASE id {$caseSql} ELSE 0 END
             WHERE id IN ({$idPlaceholders})",
            $bindings
        );
    }

    /**
     * Batch update story view counts (4 columns) in a single query.
     *
     * @param array<int, array{total: int, daily: int, weekly: int, monthly: int}> $updates
     */
    private function batchUpdateStoryViews(array $updates): void
    {
        $casesTotal = [];
        $casesDaily = [];
        $casesWeekly = [];
        $casesMonthly = [];
        $ids = [];
        $bindings = [];

        foreach ($updates as $id => $counts) {
            $casesTotal[] = 'WHEN ? THEN ?';
            $casesDaily[] = 'WHEN ? THEN ?';
            $casesWeekly[] = 'WHEN ? THEN ?';
            $casesMonthly[] = 'WHEN ? THEN ?';

            // Total
            $bindings[] = $id;
            $bindings[] = $counts['total'];
            // Daily
            $bindings[] = $id;
            $bindings[] = $counts['daily'];
            // Weekly
            $bindings[] = $id;
            $bindings[] = $counts['weekly'];
            // Monthly
            $bindings[] = $id;
            $bindings[] = $counts['monthly'];

            $ids[] = $id;
        }

        $sqlTotal = implode(' ', $casesTotal);
        $sqlDaily = implode(' ', $casesDaily);
        $sqlWeekly = implode(' ', $casesWeekly);
        $sqlMonthly = implode(' ', $casesMonthly);
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);

        $prefix = DB::getTablePrefix();

        DB::statement(
            "UPDATE {$prefix}stories SET
                view_count       = view_count       + CASE id {$sqlTotal}   ELSE 0 END,
                view_count_day   = view_count_day   + CASE id {$sqlDaily}   ELSE 0 END,
                view_count_week  = view_count_week  + CASE id {$sqlWeekly}  ELSE 0 END,
                view_count_month = view_count_month + CASE id {$sqlMonthly} ELSE 0 END
             WHERE id IN ({$idPlaceholders})",
            $bindings
        );
    }

    /**
     * Reset view counts periodically.
     */
    public function resetDailyViews(): int
    {
        return Story::query()->where('view_count_day', '>', 0)
            ->update(['view_count_day' => 0]);
    }

    public function resetWeeklyViews(): int
    {
        return Story::query()->where('view_count_week', '>', 0)
            ->update(['view_count_week' => 0]);
    }

    public function resetMonthlyViews(): int
    {
        return Story::query()->where('view_count_month', '>', 0)
            ->update(['view_count_month' => 0]);
    }

    /**
     * Update author denormalized statistics using a single UPDATE...JOIN query.
     *
     * Replaces the old N+1 pattern (1 SELECT aggregate + N individual UPDATEs)
     * with a single UPDATE...JOIN subquery that updates all affected authors at once.
     *
     * @param int[] $storyIds  Story IDs that had view changes (narrows scope to affected authors only)
     */
    public function updateAuthorStats(array $storyIds = []): void
    {
        try {
            $prefix = DB::getTablePrefix();

            // Determine which authors need updating
            if (!empty($storyIds)) {
                $authorIds = Story::query()
                    ->whereIn('id', $storyIds)
                    ->whereNotNull('author_id')
                    ->pluck('author_id')
                    ->unique()
                    ->toArray();

                if (empty($authorIds)) {
                    return;
                }

                $idPlaceholders = implode(',', array_fill(0, count($authorIds), '?'));
                $authorFilter = "AND s.author_id IN ({$idPlaceholders})";
                $authorWhere = "WHERE a.id IN ({$idPlaceholders})";
                // Bindings: subquery filter + WHERE filter (same IDs used twice)
                $bindings = array_merge($authorIds, $authorIds);
            } else {
                $authorFilter = '';
                $authorWhere = '';
                $bindings = [];
            }

            // Single UPDATE...JOIN: aggregate stories → update authors in 1 query
            DB::statement(
                "UPDATE {$prefix}authors a
                 JOIN (
                     SELECT
                         author_id,
                         COUNT(*)              AS stories_count,
                         COALESCE(SUM(total_chapters), 0) AS total_chapters,
                         COALESCE(SUM(view_count), 0)     AS total_views
                     FROM {$prefix}stories s
                     WHERE s.author_id IS NOT NULL
                       AND s.deleted_at IS NULL
                       {$authorFilter}
                     GROUP BY s.author_id
                 ) agg ON a.id = agg.author_id
                 SET a.stories_count  = agg.stories_count,
                     a.total_chapters = agg.total_chapters,
                     a.total_views    = agg.total_views
                 {$authorWhere}",
                $bindings
            );
        } catch (\Exception $e) {
            Log::warning("Failed to update author stats: {$e->getMessage()}");
        }
    }

    /**
     * Get cache statistics.
     */
    public function getStats(): array
    {
        return [
            'driver' => $this->cache->getDriver(),
            'pending_chapters' => count($this->getTrackedIds(self::CACHE_TRACKED_CHAPTERS)),
            'pending_stories' => count($this->getTrackedIds(self::CACHE_TRACKED_STORIES)),
        ];
    }

    // ============ Private Helpers ============

    private function incrementCache(string $key): void
    {
        // Use add() + increment() for atomicity.
        // add() only sets if key doesn't exist (avoids race condition).
        // If two requests both call add(), only one succeeds — the other's
        // increment() still works correctly on the existing key.
        Cache::add($key, 0, self::CACHE_TTL);
        Cache::increment($key);
    }

    private function getAndResetCache(string $key): int
    {
        $value = (int) Cache::get($key, 0);
        Cache::forget($key);
        return $value;
    }

    private function trackId(string $trackKey, int $id): void
    {
        $tracked = Cache::get($trackKey, []);
        if (!in_array($id, $tracked)) {
            $tracked[] = $id;
            Cache::put($trackKey, $tracked, self::CACHE_TTL);
        }
    }

    private function getTrackedIds(string $trackKey): array
    {
        return Cache::get($trackKey, []);
    }

    private function clearTracked(string $trackKey): void
    {
        Cache::forget($trackKey);
    }

    /**
     * Check if a visitor has viewed a chapter recently (within dedup window).
     */
    private function hasRecentView(int $chapterId, string $visitorId): bool
    {
        $key = self::CACHE_PREFIX_SEEN . $chapterId . ':' . $this->hashVisitor($visitorId);

        return Cache::has($key);
    }

    /**
     * Mark a chapter as viewed by a visitor (for dedup).
     */
    private function markAsViewed(int $chapterId, string $visitorId): void
    {
        $key = self::CACHE_PREFIX_SEEN . $chapterId . ':' . $this->hashVisitor($visitorId);

        Cache::put($key, 1, self::DEDUP_TTL);
    }

    /**
     * Hash visitor identifier for cache key safety.
     * Uses xxh3 for speed — no security requirement here.
     */
    private function hashVisitor(string $visitorId): string
    {
        return hash('xxh3', $visitorId);
    }
}

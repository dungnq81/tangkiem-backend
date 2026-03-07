<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RebuildChapterNavigation;
use App\Models\Chapter;
use App\Models\Story;
use Illuminate\Support\Facades\DB;

class ChapterObserver
{
    /**
     * Handle the Chapter "created" event.
     * Update story stats and rebuild navigation.
     */
    public function created(Chapter $chapter): void
    {
        $this->updateStoryStats($chapter);
        $this->dispatchNavigationRebuild($chapter);
        $this->clearStoryCache($chapter->story_id);
    }

    /**
     * Handle the Chapter "updated" event.
     * Rebuild navigation if published status or order changes.
     */
    public function updated(Chapter $chapter): void
    {
        $needsRebuild = $chapter->isDirty([
            'is_published',
            'chapter_number',
            'sub_chapter',
        ]);

        if ($needsRebuild) {
            $this->dispatchNavigationRebuild($chapter);
        }

        // Update story stats if published status changed
        if ($chapter->isDirty('is_published')) {
            $this->updateStoryStats($chapter);
        }

        $this->clearStoryCache($chapter->story_id);
    }

    /**
     * Handle the Chapter "deleted" event.
     * Update story stats and rebuild navigation.
     */
    public function deleted(Chapter $chapter): void
    {
        $this->updateStoryStats($chapter);
        $this->dispatchNavigationRebuild($chapter);
        $this->clearStoryCache($chapter->story_id);
    }

    /**
     * Handle the Chapter "restored" event.
     * Update story stats and rebuild navigation when restored from trash.
     */
    public function restored(Chapter $chapter): void
    {
        $this->updateStoryStats($chapter);
        $this->dispatchNavigationRebuild($chapter);
        $this->clearStoryCache($chapter->story_id);
    }

    /**
     * Handle the Chapter "forceDeleted" event.
     * Update story stats when permanently deleted.
     */
    public function forceDeleted(Chapter $chapter): void
    {
        $this->updateStoryStats($chapter);
        $this->dispatchNavigationRebuild($chapter);
        $this->clearStoryCache($chapter->story_id);
    }

    /**
     * Clear story cache using StoryCacheService.
     */
    protected function clearStoryCache(int $storyId): void
    {
        try {
            $story = Story::query()->where('id', $storyId)->first();
            if ($story) {
                /** @var \App\Services\Cache\StoryCacheService $cacheService */
                $cacheService = app(\App\Services\Cache\StoryCacheService::class);
                $cacheService->clearStoryCache($story);
            }
        } catch (\Exception $e) {
            // Log warning logic if needed
        }
    }

    /**
     * Update story's chapter statistics.
     */
    protected function updateStoryStats(Chapter $chapter): void
    {
        $story = Story::query()->where('id', $chapter->story_id)->first();

        if (!$story) {
            return;
        }

        // Use aggregate queries instead of loading all chapters into memory
        $publishedQuery = Chapter::query()
            ->where('story_id', $story->id)
            ->where('is_published', true);

        $totalChapters = (clone $publishedQuery)->count();

        // Get latest chapter (single row, not all chapters)
        $latestChapter = (clone $publishedQuery)
            ->orderByDesc('sort_key')
            ->orderByDesc('sub_chapter')
            ->first(['id', 'chapter_number', 'title', 'published_at', 'created_at']);

        // Sum word_count from chapters directly
        $totalWordCount = (clone $publishedQuery)->sum('word_count');

        $story->update([
            'total_chapters' => $totalChapters,
            'latest_chapter_number' => $latestChapter?->chapter_number ?? '0',
            'latest_chapter_title' => $latestChapter?->title,
            'last_chapter_at' => $latestChapter?->published_at ?? $latestChapter?->created_at,
            'total_word_count' => $totalWordCount,
        ]);
    }

    /**
     * Dispatch job to rebuild chapter navigation.
     */
    protected function dispatchNavigationRebuild(Chapter $chapter): void
    {
        // Dispatch job (sync in dev, queue in production)
        if (class_exists(RebuildChapterNavigation::class)) {
            RebuildChapterNavigation::dispatch($chapter->story_id);
        } else {
            // Fallback: rebuild inline with batch query
            $this->rebuildNavigation($chapter->story_id);
        }
    }

    /**
     * Rebuild chapter navigation (prev/next) for a story.
     *
     * Uses a single CASE/WHEN UPDATE query instead of N individual UPDATEs.
     */
    protected function rebuildNavigation(int $storyId): void
    {
        $chapterIds = Chapter::query()->where('story_id', $storyId)
            ->where('is_published', true)
            ->orderBy('sort_key')
            ->orderBy('sub_chapter')
            ->pluck('id')
            ->toArray();

        if (empty($chapterIds)) {
            return;
        }

        $prevCases = [];
        $nextCases = [];

        foreach ($chapterIds as $index => $id) {
            $prev = $chapterIds[$index - 1] ?? 'NULL';
            $next = $chapterIds[$index + 1] ?? 'NULL';
            $prevCases[] = "WHEN {$id} THEN {$prev}";
            $nextCases[] = "WHEN {$id} THEN {$next}";
        }

        $ids = implode(',', $chapterIds);
        $prefix = DB::getTablePrefix();

        DB::update("
            UPDATE {$prefix}chapters SET
                prev_chapter_id = CASE id " . implode(' ', $prevCases) . ' END,
                next_chapter_id = CASE id ' . implode(' ', $nextCases) . " END
            WHERE id IN ({$ids})
        ");
    }
}

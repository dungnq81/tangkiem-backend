<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\StoryStatus;
use App\Models\Author;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\SlugRedirect;
use App\Models\Story;
use App\Models\User;
use App\Notifications\StoryCompletedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\ResponseCache\Facades\ResponseCache;

class StoryObserver
{
    /**
     * Handle the Story "saving" event.
     * Sync alternative_titles JSON to flattened text for full-text search.
     */
    public function saving(Story $story): void
    {
        if ($story->isDirty('alternative_titles')) {
            $titles = $story->alternative_titles ?? [];
            $story->alternative_titles_text = implode(' | ', array_filter($titles));
        }
    }

    /**
     * Handle the Story "updating" event.
     * Create slug redirect when slug changes.
     */
    public function updating(Story $story): void
    {
        if ($story->isDirty('slug')) {
            $oldSlug = $story->getOriginal('slug');

            if ($oldSlug) {
                SlugRedirect::createForSlugChange('story', $story->id, $oldSlug);
            }
        }
    }

    /**
     * Handle the Story "saved" event.
     * Clear story cache and sync author stats.
     *
     * NOTE: primary_category_id sync is now handled in Filament Pages
     * (CreateStory::afterCreate, EditStory::afterSave) since we sync
     * from pivot → primary_category_id (not the other way around)
     */
    public function saved(Story $story): void
    {
        // Sync author stats if author changed or stats fields changed
        if ($story->isDirty(['author_id', 'view_count', 'total_chapters'])) {
            $this->syncAuthorStats($story->author_id);

            // If author changed, also sync old author
            if ($story->isDirty('author_id') && $story->getOriginal('author_id')) {
                $this->syncAuthorStats($story->getOriginal('author_id'));
            }
        }

        // Notify bookmarkers when story status changes to completed
        if (
            $story->isDirty('status')
            && $story->status === StoryStatus::COMPLETED
            && $story->getOriginal('status') !== StoryStatus::COMPLETED->value
        ) {
            $this->notifyStoryCompleted($story);
        }

        // Clear cache
        $this->clearCache($story);
    }

    /**
     * Handle the Story "deleted" event (soft delete).
     * Cascade soft delete to chapters and comments.
     */
    public function deleted(Story $story): void
    {
        // Only cascade on soft delete, not force delete
        // (force delete is handled by FK cascade + forceDeleting event)
        if (!$story->isForceDeleting()) {
            $this->cascadeSoftDelete($story);
        }

        // Sync author stats when story is deleted
        if ($story->author_id) {
            $this->syncAuthorStats($story->author_id);
        }

        $this->clearCache($story);
    }

    /**
     * Handle the Story "restored" event.
     * Cascade restore to chapters and comments that were soft-deleted along with the story.
     */
    public function restored(Story $story): void
    {
        // Restore chapters (without firing ChapterObserver N times)
        Chapter::withoutEvents(function () use ($story) {
            Chapter::onlyTrashed()
                ->where('story_id', $story->id)
                ->restore();
        });

        // Restore comments on this story
        Comment::onlyTrashed()
            ->where('commentable_type', 'story')
            ->where('commentable_id', $story->id)
            ->restore();

        // Restore comments on all chapters of this story
        $chapterIds = $story->chapters()->pluck('id');

        if ($chapterIds->isNotEmpty()) {
            Comment::onlyTrashed()
                ->where('commentable_type', 'chapter')
                ->whereIn('commentable_id', $chapterIds)
                ->restore();
        }

        // Sync author stats
        if ($story->author_id) {
            $this->syncAuthorStats($story->author_id);
        }

        $this->clearCache($story);

        Log::info("Story #{$story->id} restored — chapters and comments cascaded.");
    }

    /**
     * Handle the Story "forceDeleting" event.
     * Clean up polymorphic comments before the DB row is removed.
     * (ratings, bookmarks, chapters, etc. are handled by FK cascadeOnDelete)
     */
    public function forceDeleting(Story $story): void
    {
        // Collect chapter IDs before FK cascade removes them
        $chapterIds = $story->chapters()->withTrashed()->pluck('id');

        // Force delete comments on this story (polymorphic — no FK cascade)
        Comment::withTrashed()
            ->where('commentable_type', 'story')
            ->where('commentable_id', $story->id)
            ->forceDelete();

        // Force delete comments on all chapters of this story
        if ($chapterIds->isNotEmpty()) {
            Comment::withTrashed()
                ->where('commentable_type', 'chapter')
                ->whereIn('commentable_id', $chapterIds)
                ->forceDelete();
        }

        Log::info("Story #{$story->id} force deleting — cleaned up {$chapterIds->count()} chapters' comments.");
    }

    // ═══════════════════════════════════════════════════════════════
    // Cascade Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cascade soft delete to chapters and comments.
     */
    protected function cascadeSoftDelete(Story $story): void
    {
        // Soft delete chapters without firing ChapterObserver for each one
        // (avoids N calls to updateStoryStats + dispatchNavigationRebuild)
        $chapterIds = $story->chapters()->pluck('id');

        Chapter::withoutEvents(function () use ($story) {
            $story->chapters()->delete();
        });

        // Soft delete comments on this story
        Comment::query()
            ->where('commentable_type', 'story')
            ->where('commentable_id', $story->id)
            ->delete();

        // Soft delete comments on all chapters of this story
        if ($chapterIds->isNotEmpty()) {
            Comment::query()
                ->where('commentable_type', 'chapter')
                ->whereIn('commentable_id', $chapterIds)
                ->delete();
        }

        Log::info("Story #{$story->id} soft deleted — cascaded to {$chapterIds->count()} chapters and their comments.");
    }

    // ═══════════════════════════════════════════════════════════════
    // Stats & Cache Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync author's aggregate statistics from stories.
     * Called when story is created, updated, or deleted.
     */
    protected function syncAuthorStats(?int $authorId): void
    {
        if (!$authorId) {
            return;
        }

        $author = Author::query()->find($authorId);

        if (!$author) {
            return;
        }

        // Calculate aggregate stats from all author's stories (excluding soft deleted)
        $stats = Story::query()
            ->where('author_id', $authorId)
            ->selectRaw('COUNT(*) as stories_count')
            ->selectRaw('COALESCE(SUM(total_chapters), 0) as total_chapters')
            ->selectRaw('COALESCE(SUM(view_count), 0) as total_views')
            ->first();

        $author->update([
            'stories_count' => $stats->stories_count ?? 0,
            'total_chapters' => $stats->total_chapters ?? 0,
            'total_views' => $stats->total_views ?? 0,
        ]);
    }

    /**
     * Clear story cache using StoryCacheService.
     */
    protected function clearCache(Story $story): void
    {
        try {
            /** @var \App\Services\Cache\StoryCacheService $cacheService */
            $cacheService = app(\App\Services\Cache\StoryCacheService::class);
            $cacheService->clearStoryCache($story);
        } catch (\Exception $e) {
            Log::warning("Failed to clear story cache: {$e->getMessage()}");
        }

        // Clear API response cache so new requests fetch fresh data
        try {
            ResponseCache::clear();
        } catch (\Exception $e) {
            Log::warning("Failed to clear response cache: {$e->getMessage()}");
        }
    }

    /**
     * Notify all bookmarkers that a story has been completed.
     */
    protected function notifyStoryCompleted(Story $story): void
    {
        $users = User::query()
            ->whereHas('bookmarks', fn ($q) => $q->where('story_id', $story->id))
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, StoryCompletedNotification::fromStory($story));

        Log::info("StoryCompleted: notified {$users->count()} bookmarker(s) for [{$story->title}]");
    }
}

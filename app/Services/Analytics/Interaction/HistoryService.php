<?php

declare(strict_types=1);

namespace App\Services\Analytics\Interaction;

use App\Models\Chapter;
use App\Models\ReadingHistory;
use App\Models\Story;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * HistoryService — Per-site reading history management.
 *
 * Each FE site has isolated reading history. A user's progress
 * on Site A is independent from Site B. NULL site = legacy/global.
 */
class HistoryService
{
    /**
     * List reading history for a user, scoped to a specific site.
     */
    public function list(User $user, ?int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        return ReadingHistory::query()
            ->where('user_id', $user->id)
            ->siteAware($siteId)
            ->with([
                'story' => fn ($q) => $q->published(),
                'story.author',
                'story.primaryCategory',
                'story.coverImage',
                'chapter:id,chapter_number,title,slug',
            ])
            ->latest('read_at')
            ->paginate($perPage);
    }

    /**
     * Save or update reading progress for a specific site.
     */
    public function upsert(User $user, Story $story, Chapter $chapter, int $progress, ?int $siteId): ReadingHistory
    {
        return DB::transaction(function () use ($user, $story, $chapter, $progress, $siteId) {
            $existing = $this->findForUserLocked($user->id, $story->id, $siteId);

            if ($existing) {
                $existing->update([
                    'chapter_id' => $chapter->id,
                    'progress'   => $progress,
                    'read_at'    => now(),
                ]);

                return $existing;
            }

            return ReadingHistory::create([
                'user_id'       => $user->id,
                'story_id'      => $story->id,
                'chapter_id'    => $chapter->id,
                'progress'      => $progress,
                'read_at'       => now(),
                'api_domain_id' => $siteId,
            ]);
        });
    }

    /**
     * Delete a single history entry (verify ownership + site).
     */
    public function delete(User $user, int $historyId, ?int $siteId): bool
    {
        $entry = ReadingHistory::query()
            ->where('user_id', $user->id)
            ->where('id', $historyId)
            ->forSiteExact($siteId)
            ->first();

        if (!$entry) {
            return false;
        }

        $entry->delete();

        return true;
    }

    /**
     * Clear all reading history for a user on a specific site.
     * Returns number of deleted entries.
     */
    public function clearAll(User $user, ?int $siteId): int
    {
        $query = ReadingHistory::query()
            ->where('user_id', $user->id)
            ->forSiteExact($siteId);

        return $query->delete();
    }

    /**
     * Get current progress for a story on a specific site.
     */
    public function getProgress(User $user, int $storyId, ?int $siteId): ?ReadingHistory
    {
        return $this->findForUser($user->id, $storyId, $siteId);
    }

    /**
     * NULL-safe finder.
     */
    private function findForUser(int $userId, int $storyId, ?int $siteId): ?ReadingHistory
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->first();
    }

    /**
     * NULL-safe finder with pessimistic lock.
     */
    private function findForUserLocked(int $userId, int $storyId, ?int $siteId): ?ReadingHistory
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->lockForUpdate()->first();
    }

    /**
     * Build the base query for finding a history entry by user, story, and site.
     */
    private function buildFindQuery(int $userId, int $storyId, ?int $siteId)
    {
        $query = ReadingHistory::query()
            ->where('user_id', $userId)
            ->where('story_id', $storyId);

        if ($siteId !== null) {
            $query->where('api_domain_id', $siteId);
        } else {
            $query->whereNull('api_domain_id');
        }

        return $query;
    }
}

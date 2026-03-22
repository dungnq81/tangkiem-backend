<?php

declare(strict_types=1);

namespace App\Services\Analytics\Interaction;

use App\Models\Bookmark;
use App\Models\Story;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * BookmarkService — Per-site bookmark management.
 *
 * Each FE site has isolated bookmarks. A user can bookmark the same story
 * on different sites without conflict. NULL site = legacy/global.
 *
 * Triggers BookmarkObserver events (created/deleted) for favorite_count sync.
 */
class BookmarkService
{
    /**
     * List bookmarks for a user, scoped to a specific site.
     */
    public function list(User $user, ?int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        return Bookmark::query()
            ->where('user_id', $user->id)
            ->siteAware($siteId)
            ->with([
                'story' => fn ($q) => $q->published(),
                'story.author',
                'story.primaryCategory',
                'story.coverImage',
            ])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Add a bookmark. Returns [Bookmark, wasCreated].
     *
     * Uses a transaction with pessimistic lock to prevent race condition
     * (two concurrent requests creating duplicate bookmarks).
     *
     * @return array{0: Bookmark, 1: bool}
     */
    public function add(User $user, Story $story, ?int $siteId): array
    {
        return DB::transaction(function () use ($user, $story, $siteId) {
            // Lock the row if it exists to prevent duplicate inserts
            $bookmark = $this->findForUserLocked($user->id, $story->id, $siteId);

            if ($bookmark) {
                return [$bookmark, false];
            }

            $bookmark = Bookmark::create([
                'user_id'       => $user->id,
                'story_id'      => $story->id,
                'api_domain_id' => $siteId,
            ]);

            return [$bookmark, true];
        });
    }

    /**
     * Remove a bookmark. Returns true if deleted.
     */
    public function remove(User $user, int $storyId, ?int $siteId): bool
    {
        $bookmark = $this->findForUser($user->id, $storyId, $siteId);

        if (!$bookmark) {
            return false;
        }

        // Use delete() on model instance to trigger BookmarkObserver::deleted()
        $bookmark->delete();

        return true;
    }

    /**
     * NULL-safe finder: correctly uses whereNull for NULL site.
     */
    private function findForUser(int $userId, int $storyId, ?int $siteId): ?Bookmark
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->first();
    }

    /**
     * NULL-safe finder with pessimistic lock for write operations.
     */
    private function findForUserLocked(int $userId, int $storyId, ?int $siteId): ?Bookmark
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->lockForUpdate()->first();
    }

    /**
     * Build the base query for finding a bookmark by user, story, and site.
     */
    private function buildFindQuery(int $userId, int $storyId, ?int $siteId)
    {
        $query = Bookmark::query()
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

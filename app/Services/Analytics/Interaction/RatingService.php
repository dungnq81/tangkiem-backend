<?php

declare(strict_types=1);

namespace App\Services\Analytics\Interaction;

use App\Models\Rating;
use App\Models\Story;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * RatingService — Per-site rating/review management.
 *
 * Each FE site has isolated ratings. A user can rate the same story
 * on different sites. NULL site = legacy/global.
 */
class RatingService
{
    /**
     * Rate or update rating for a story on a specific site.
     *
     * @return array{0: Rating, 1: bool} [rating, wasCreated]
     */
    public function rate(User $user, Story $story, int $score, ?string $review, ?int $siteId): array
    {
        return DB::transaction(function () use ($user, $story, $score, $review, $siteId) {
            $existing = $this->findForUserLocked($user->id, $story->id, $siteId);

            if ($existing) {
                $existing->update([
                    'rating' => $score,
                    'review' => $review,
                ]);

                return [$existing, false];
            }

            $rating = Rating::create([
                'user_id'       => $user->id,
                'story_id'      => $story->id,
                'rating'        => $score,
                'review'        => $review,
                'api_domain_id' => $siteId,
            ]);

            return [$rating, true];
        });
    }

    /**
     * Remove a rating. Returns true if deleted.
     */
    public function remove(User $user, int $storyId, ?int $siteId): bool
    {
        $rating = $this->findForUser($user->id, $storyId, $siteId);

        if (!$rating) {
            return false;
        }

        $rating->delete();

        return true;
    }

    /**
     * Get reviews for a story, optionally scoped to a site.
     *
     * When $siteId is null, returns ALL ratings across all sites (global view).
     */
    public function reviews(int $storyId, ?int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        return Rating::query()
            ->where('story_id', $storyId)
            ->siteAware($siteId)
            ->with('user:id,name')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get user's rating for a story on a specific site.
     */
    public function getUserRating(User $user, int $storyId, ?int $siteId): ?Rating
    {
        return $this->findForUser($user->id, $storyId, $siteId);
    }

    /**
     * NULL-safe finder.
     */
    private function findForUser(int $userId, int $storyId, ?int $siteId): ?Rating
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->first();
    }

    /**
     * NULL-safe finder with pessimistic lock.
     */
    private function findForUserLocked(int $userId, int $storyId, ?int $siteId): ?Rating
    {
        return $this->buildFindQuery($userId, $storyId, $siteId)->lockForUpdate()->first();
    }

    /**
     * Build the base query for finding a rating by user, story, and site.
     */
    private function buildFindQuery(int $userId, int $storyId, ?int $siteId)
    {
        $query = Rating::query()
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

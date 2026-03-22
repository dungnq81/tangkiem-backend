<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Bookmark;
use App\Models\Story;

/**
 * Sync stories.favorite_count when bookmarks are created or deleted.
 */
class BookmarkObserver
{
    /**
     * Handle the Bookmark "created" event.
     */
    public function created(Bookmark $bookmark): void
    {
        $this->syncFavoriteCount($bookmark->story_id);
    }

    /**
     * Handle the Bookmark "deleted" event.
     */
    public function deleted(Bookmark $bookmark): void
    {
        $this->syncFavoriteCount($bookmark->story_id);
    }

    /**
     * Sync favorite_count on the story.
     */
    protected function syncFavoriteCount(int $storyId): void
    {
        $count = Bookmark::where('story_id', $storyId)->count();

        Story::query()
            ->where('id', $storyId)
            ->update(['favorite_count' => $count]);
    }
}

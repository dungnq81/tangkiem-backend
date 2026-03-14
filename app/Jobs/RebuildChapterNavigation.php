<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to rebuild chapter navigation (prev/next links) for a story.
 *
 * Dispatched when chapters are created, updated, deleted, or reordered.
 * Uses a single CASE/WHEN UPDATE query instead of N individual UPDATEs.
 */
class RebuildChapterNavigation implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $storyId,
    ) {
    }

    /**
     * Execute the job.
     *
     * Builds prev/next chapter navigation using a single batch query
     * instead of N individual UPDATE queries.
     */
    public function handle(): void
    {
        $chapterIds = Chapter::query()
            ->where('story_id', $this->storyId)
            ->published()
            ->ordered()
            ->pluck('id')
            ->toArray();

        $count = count($chapterIds);

        if ($count === 0) {
            // Clear navigation on all chapters (even unpublished)
            Chapter::query()
                ->where('story_id', $this->storyId)
                ->whereNotNull('prev_chapter_id')
                ->orWhereNotNull('next_chapter_id')
                ->where('story_id', $this->storyId)
                ->update(['prev_chapter_id' => null, 'next_chapter_id' => null]);

            Log::info("RebuildChapterNavigation: No published chapters for story {$this->storyId}");

            return;
        }

        // Build CASE/WHEN for batch update (1 query instead of N)
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

        Log::info("RebuildChapterNavigation: Updated {$count} chapters for story {$this->storyId} (1 batch query)");
    }
}

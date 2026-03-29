<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Jobs\RebuildChapterNavigation;
use App\Models\Chapter;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\Story;
use App\Services\Scraper\Contracts\EntityImporterInterface;
use App\Services\Scraper\Importers\AuthorImporter;
use App\Services\Scraper\Importers\CategoryImporter;
use App\Services\Scraper\Importers\ChapterImporter;
use App\Services\Scraper\Importers\StoryImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Import orchestrator — routes items to entity-specific importers.
 *
 * Handles:
 * - Sequential numbering setup (position-based rank approach)
 * - Chapter event suppression + deferred navigation rebuild
 * - Status tracking and job completion
 *
 * Delegates entity-specific logic to:
 * - Importers\CategoryImporter
 * - Importers\AuthorImporter
 * - Importers\StoryImporter
 * - Importers\ChapterImporter
 */
class ScrapeImporter
{
    /**
     * Import selected items from a job.
     */
    public function importSelected(ScrapeJob $job): array
    {
        $results = ['imported' => 0, 'merged' => 0, 'errors' => 0, 'skipped' => 0];

        // Track story IDs that need navigation rebuild (for chapter imports)
        $storyIdsNeedingNavRebuild = [];
        $isChapterJob = in_array($job->entity_type, [ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL]);

        // Sequential numbering: position-based rank approach (deterministic, retry-safe)
        $defaults = $job->import_defaults ?? [];
        $sequentialNumbering = (bool) ($defaults['sequential_numbering'] ?? false);
        $sequentialRankMap = [];
        $sequentialBaseOffset = 0;

        if ($sequentialNumbering && $isChapterJob && $job->parent_story_id) {
            // Build rank map: item_id → 1-based position (by sort_order among ALL items of the job)
            $allItemIds = $job->items()->orderBy('sort_order')->pluck('id');
            $sequentialRankMap = [];
            foreach ($allItemIds as $rank => $id) {
                $sequentialRankMap[$id] = $rank + 1; // 1-based
            }

            // Base offset: max chapter_number for chapters NOT imported from this job's items.
            $baseQuery = Chapter::where('story_id', $job->parent_story_id)
                ->where(function ($q) use ($job) {
                    $q->whereNotIn('scrape_hash', function ($sub) use ($job) {
                        $sub->select('source_hash')
                            ->from('scrape_items')
                            ->where('job_id', $job->id)
                            ->whereNotNull('source_hash');
                    })
                    ->orWhereNull('scrape_hash');
                });

            $maxExisting = $baseQuery->max('sort_key');
            $sequentialBaseOffset = $maxExisting ? (int) ceil((float) $maxExisting) : 0;
        }

        $importer = $this->resolveImporter($job->entity_type);

        // Chunk-based processing: 500 items at a time to prevent OOM on 10k+ imports
        // Note: chunkById orders by id internally. sort_order is irrelevant here because
        // sequential numbers are pre-computed from the rank map above.
        $job->items()
            ->where('status', ScrapeItem::STATUS_SELECTED)
            ->chunkById(500, function (\Illuminate\Support\Collection $items) use (
                $importer, $job, &$results, &$storyIdsNeedingNavRebuild,
                $isChapterJob, $sequentialNumbering, $sequentialRankMap, $sequentialBaseOffset,
            ) {
                /** @var ScrapeItem $item */
                foreach ($items as $item) {
                    try {
                        // Skip if already imported (dedup via scrape_hash)
                        if ($this->isAlreadyImported($item, $job->entity_type)) {
                            $item->update(['status' => ScrapeItem::STATUS_SKIPPED]);
                            $results['skipped']++;
                            continue;
                        }

                        // Determine sequential number for this item (position-based, not counter-based)
                        $sequentialNumber = null;
                        if ($sequentialNumbering && $isChapterJob && isset($sequentialRankMap[$item->id])) {
                            $sequentialNumber = $sequentialBaseOffset + $sequentialRankMap[$item->id];
                        }

                        // For chapter batch: suppress observer's navigation dispatch
                        if ($isChapterJob) {
                            Chapter::withoutEvents(function () use ($importer, $item, $job, &$results, &$storyIdsNeedingNavRebuild, $sequentialNumber) {
                                $outcome = $importer->import($item, $job, $sequentialNumber);

                                if ($outcome === 'merged') {
                                    $item->update(['status' => ScrapeItem::STATUS_MERGED, 'error_message' => null]);
                                    $results['merged']++;
                                } else {
                                    $item->update(['status' => ScrapeItem::STATUS_IMPORTED, 'error_message' => null]);
                                    $results['imported']++;
                                }

                                // Collect story ID for deferred navigation rebuild
                                if ($job->parent_story_id) {
                                    $storyIdsNeedingNavRebuild[$job->parent_story_id] = true;
                                }
                            });
                        } else {
                            $outcome = $importer->import($item, $job);

                            if ($outcome === 'merged') {
                                $item->update(['status' => ScrapeItem::STATUS_MERGED, 'error_message' => null]);
                                $results['merged']++;
                            } else {
                                $item->update(['status' => ScrapeItem::STATUS_IMPORTED, 'error_message' => null]);
                                $results['imported']++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $item->update([
                            'status' => ScrapeItem::STATUS_ERROR,
                            'error_message' => Str::limit($e->getMessage(), 500),
                        ]);
                        $results['errors']++;
                        Log::error('Import item failed', [
                            'item_id' => $item->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // Deferred: rebuild navigation + update story stats ONCE per story
        if (!empty($storyIdsNeedingNavRebuild)) {
            foreach (array_keys($storyIdsNeedingNavRebuild) as $storyId) {
                RebuildChapterNavigation::dispatch($storyId);
                $this->updateStoryChapterStats($storyId);
            }
        }

        // Single query: check remaining items by status
        $remaining = $job->items()
            ->selectRaw("
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as selected_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft_count
            ", [ScrapeItem::STATUS_SELECTED, ScrapeItem::STATUS_DRAFT])
            ->first();

        $remainingSelected = (int) ($remaining->selected_count ?? 0);
        $remainingDraft = (int) ($remaining->draft_count ?? 0);

        if ($remainingSelected === 0 && $remainingDraft === 0) {
            $job->markDone();
        } elseif ($remainingSelected === 0) {
            $job->markScraped();
        }

        return $results;
    }

    /**
     * Import a single item based on entity type.
     * Kept for backward compatibility with callers that use importItem() directly.
     *
     * @return string 'imported' or 'merged'
     */
    public function importItem(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string
    {
        return $this->resolveImporter($job->entity_type)->import($item, $job, $sequentialNumber);
    }

    /**
     * Resolve the correct importer for the entity type.
     */
    protected function resolveImporter(string $entityType): EntityImporterInterface
    {
        return match ($entityType) {
            ScrapeJob::ENTITY_CATEGORY                                  => new CategoryImporter(),
            ScrapeJob::ENTITY_AUTHOR                                    => new AuthorImporter(),
            ScrapeJob::ENTITY_STORY                                     => new StoryImporter(),
            ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL => new ChapterImporter(),
        };
    }

    /**
     * Check dedup directly — avoids N+1 by accepting entity_type as a parameter.
     * Respects soft deletes: deleted records don't block re-import.
     */
    protected function isAlreadyImported(ScrapeItem $item, string $entityType): bool
    {
        // Category and chapter handle dedup + merge internally
        if (in_array($entityType, ['category', 'chapter', 'chapter_detail'])) {
            return false;
        }

        $table = match ($entityType) {
            'author' => 'authors',
            'story' => 'stories',
            default => null,
        };

        if (! $table || ! $item->source_hash) {
            return false;
        }

        return DB::table($table)
            ->where('scrape_hash', $item->source_hash)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Update story chapter stats (deferred version for batch imports).
     * Replicates ChapterObserver::updateStoryStats logic.
     */
    protected function updateStoryChapterStats(int $storyId): void
    {
        $story = Story::find($storyId);
        if (!$story) {
            return;
        }

        // Single aggregate query for count + sum (was 2 separate queries)
        $stats = Chapter::query()
            ->where('story_id', $storyId)
            ->where('is_published', true)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(word_count), 0) as total_words')
            ->first();

        $totalChapters = (int) $stats->total;
        $totalWordCount = (int) $stats->total_words;

        // Separate query for latest chapter (needs ORDER BY, can't easily combine with aggregate)
        $latestChapter = $totalChapters > 0
            ? Chapter::query()
                ->where('story_id', $storyId)
                ->where('is_published', true)
                ->orderByDesc('sort_key')
                ->orderByDesc('sub_chapter')
                ->first(['id', 'chapter_number', 'title', 'published_at', 'created_at'])
            : null;

        $story->update([
            'total_chapters' => $totalChapters,
            'latest_chapter_number' => $latestChapter?->chapter_number ?? '0',
            'latest_chapter_title' => $latestChapter?->title,
            'last_chapter_at' => $latestChapter?->published_at ?? $latestChapter?->created_at,
            'total_word_count' => $totalWordCount,
        ]);
    }
}

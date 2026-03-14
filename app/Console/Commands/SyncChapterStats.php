<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chapter;
use App\Models\Story;
use Illuminate\Console\Command;

class SyncChapterStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stories:sync-chapter-stats
                            {--story= : Sync only a specific story by ID}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize total_chapters and related stats for all stories';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $storyId = $this->option('story');

        $this->info($dryRun ? '🔍 Dry run mode - no changes will be made' : '🔄 Syncing chapter statistics...');
        $this->newLine();

        $query = Story::query()->withTrashed();

        if ($storyId) {
            $query->where('id', $storyId);
        }

        $totalCount = $query->count();

        if ($totalCount === 0) {
            $this->warn('No stories found.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $details = [];

        $query->chunk(200, function ($stories) use ($dryRun, &$updated, &$unchanged, &$details, $bar) {
            foreach ($stories as $story) {
                /** @var Story $story */
                $result = $this->syncStoryStats($story, $dryRun);

                if ($result['changed']) {
                    $updated++;
                    $details[] = $result;
                } else {
                    $unchanged++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Show summary
        $this->info("✅ Done! {$updated} stories updated, {$unchanged} unchanged.");

        // Show details if any changes
        if ($updated > 0 && $this->option('verbose')) {
            $this->newLine();
            $this->table(
                ['Story ID', 'Title', 'Old Chapters', 'New Chapters'],
                array_map(fn ($d) => [
                    $d['story_id'],
                    substr($d['title'], 0, 40) . (strlen($d['title']) > 40 ? '...' : ''),
                    $d['old_count'],
                    $d['new_count'],
                ], $details)
            );
        }

        return self::SUCCESS;
    }

    /**
     * Sync stats for a single story.
     */
    protected function syncStoryStats(Story $story, bool $dryRun): array
    {
        // Count published chapters (excluding soft deleted)
        $publishedChapters = Chapter::query()
            ->where('story_id', $story->id)
            ->where('is_published', true)
            ->orderByDesc('sort_key')
            ->orderByDesc('sub_chapter')
            ->get();

        $latestChapter = $publishedChapters->first();

        // Calculate total word count from chapters (word_count is per-chapter, set by observer)
        $totalWordCount = Chapter::query()
            ->where('story_id', $story->id)
            ->where('is_published', true)
            ->sum('word_count');

        $newCount = $publishedChapters->count();
        $oldCount = $story->total_chapters ?? 0;
        $changed = ($oldCount !== $newCount);

        $updates = [
            'total_chapters' => $newCount,
            'latest_chapter_number' => $latestChapter?->chapter_number ?? '0',
            'latest_chapter_title' => $latestChapter?->title,
            'last_chapter_at' => $latestChapter?->published_at ?? $latestChapter?->created_at,
            'total_word_count' => $totalWordCount,
        ];

        if (!$dryRun && $changed) {
            $story->update($updates);
        }

        return [
            'changed' => $changed,
            'story_id' => $story->id,
            'title' => $story->title,
            'old_count' => $oldCount,
            'new_count' => $newCount,
        ];
    }
}

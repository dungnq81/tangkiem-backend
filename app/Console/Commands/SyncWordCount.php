<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chapter;
use App\Models\ChapterContent;
use Illuminate\Console\Command;

class SyncWordCount extends Command
{
    protected $signature = 'chapters:sync-word-count
                            {--story= : Only sync chapters of a specific story ID}
                            {--dry-run : Show stats without making changes}';

    protected $description = 'Recalculate word_count for all chapters (fixes old data that stored character count instead of word count)';

    /**
     * Chunk size for processing chapters.
     */
    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $storyId = $this->option('story');

        $this->info($dryRun
            ? '🔍 Dry run — no changes will be made'
            : '🔄 Recalculating word_count for all chapters...'
        );

        // Build query: chapters that have content
        $query = ChapterContent::query();

        if ($storyId) {
            $chapterIds = Chapter::where('story_id', $storyId)->pluck('id');
            $query->whereIn('chapter_id', $chapterIds);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No chapter content found.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} chapters with content.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $examples = [];

        $query->select(['id', 'chapter_id', 'content'])
            ->chunk(self::CHUNK_SIZE, function ($contents) use ($dryRun, &$updated, &$unchanged, &$examples, $bar) {
                foreach ($contents as $content) {
                    /** @var ChapterContent $content */
                    $plainText = trim(strip_tags($content->content ?? ''));
                    $newWordCount = Chapter::countWords($plainText);

                    $chapter = Chapter::find($content->chapter_id);

                    if (! $chapter) {
                        $bar->advance();
                        continue;
                    }

                    $oldWordCount = $chapter->word_count ?? 0;

                    if ($oldWordCount !== $newWordCount) {
                        if (! $dryRun) {
                            $chapter->updateQuietly(['word_count' => $newWordCount]);
                        }

                        $updated++;

                        // Collect first 5 examples for verbose output
                        if (count($examples) < 5) {
                            $examples[] = [
                                'chapter_id' => $chapter->id,
                                'story_id'   => $chapter->story_id,
                                'old'        => $oldWordCount,
                                'new'        => $newWordCount,
                            ];
                        }
                    } else {
                        $unchanged++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info("✅ Done! {$updated} chapters updated, {$unchanged} unchanged.");

        if (! empty($examples)) {
            $this->newLine();
            $this->info('Sample changes (old = character count, new = word count):');
            $this->table(
                ['Chapter ID', 'Story ID', 'Old (chars)', 'New (words)'],
                array_map(fn ($e) => [$e['chapter_id'], $e['story_id'], number_format($e['old']), number_format($e['new'])], $examples)
            );
        }

        // Remind to sync story stats
        if ($updated > 0 && ! $dryRun) {
            $this->newLine();
            $this->info('📊 Now syncing story-level total_word_count...');
            $this->call('stories:sync-chapter-stats');
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RebuildChapterNavigation;
use App\Models\Chapter;
use App\Models\ScrapeItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix chapter numbering for stories.
 *
 * Two modes:
 * 1. Default: Re-number based on original scrape sort_order (requires scrape_items data).
 * 2. --resequence: Re-number ALL chapters 1,2,3... based on current sort_key order.
 *    Use this when scrape data has been deleted, or for any general re-numbering need.
 *
 * Workflow for --resequence:
 *   1. Fix the misplaced chapter's chapter_number in admin (e.g., 912 → 3)
 *      → sort_key auto-updates since it's a generated column
 *   2. Run: php artisan chapters:fix-numbering {story_id} --resequence
 *      → All chapters get clean sequential numbers based on sort_key order
 */
class FixChapterNumbering extends Command
{
    protected $signature = 'chapters:fix-numbering
        {story_id : Story ID to fix}
        {--job= : Limit to chapters from a specific ScrapeJob ID}
        {--offset=0 : Base offset (add to all numbers)}
        {--resequence : Re-number by current sort_key order (no scrape data needed)}
        {--by-title : Re-number by parsing chapter number from title (e.g. "Chương 50" → position 50)}
        {--move=* : Move chapter to position before resequencing. Format: CHAPTER_ID:POSITION (e.g. --move=12345:50)}
        {--dry-run : Show changes without applying}';

    protected $description = 'Re-number chapters to fix sequential numbering gaps/shifts';

    public function handle(): int
    {
        // --by-title: parse numbers from titles and reorder
        if ($this->option('by-title')) {
            return $this->handleByTitle();
        }

        // Handle --move first: safely reposition chapters before resequencing
        $moves = $this->option('move');
        if (! empty($moves)) {
            $this->applyMoves($moves);
        }

        if ($this->option('resequence') || ! empty($moves)) {
            return $this->handleResequence();
        }

        return $this->handleScrapeBasedFix();
    }

    /**
     * Safely move chapters to new positions using the .5 decimal trick.
     *
     * E.g., --move=12345:50 sets chapter_number to '49.5', which:
     * - Doesn't conflict with unique constraint (no chapter '49.5' exists)
     * - Gives sort_key = 49.50, placing it between chapters 49 and 50
     * - Gets cleaned up to integer by subsequent --resequence
     */
    private function applyMoves(array $moves): void
    {
        $storyId = (int) $this->argument('story_id');

        foreach ($moves as $move) {
            if (! preg_match('/^(\d+):(\d+)$/', $move, $m)) {
                $this->components->error("Invalid --move format: '{$move}'. Expected CHAPTER_ID:POSITION (e.g. 12345:50)");
                continue;
            }

            $chapterId = (int) $m[1];
            $targetPosition = (int) $m[2];

            $chapter = Chapter::where('id', $chapterId)
                ->where('story_id', $storyId)
                ->first(['id', 'chapter_number', 'title']);

            if (! $chapter) {
                $this->components->error("Chapter #{$chapterId} not found in story #{$storyId}.");
                continue;
            }

            // Use .5 to slip between targetPosition-1 and targetPosition
            $tempNumber = ($targetPosition - 1) . '.5';

            $prefix = DB::getTablePrefix();
            DB::statement(
                "UPDATE {$prefix}chapters SET chapter_number = ? WHERE id = ?",
                [$tempNumber, $chapterId]
            );

            $this->components->info(
                "Moved chapter #{$chapterId} \"{$chapter->title}\" "
                . "from #{$chapter->chapter_number} → temporary #{$tempNumber} "
                . "(will become #{$targetPosition} after resequence)"
            );
        }
    }

    /**
     * Mode 1: Re-number based on scrape sort_order (original logic).
     */
    private function handleScrapeBasedFix(): int
    {
        $storyId = (int) $this->argument('story_id');
        $jobId = $this->option('job') ? (int) $this->option('job') : null;
        $offset = (int) $this->option('offset');
        $isDryRun = $this->option('dry-run');

        $query = Chapter::where('story_id', $storyId)
            ->whereNotNull('scrape_hash')
            ->orderBy('sort_key')
            ->orderBy('sub_chapter');

        if ($jobId) {
            $query->whereIn('scrape_hash', function ($q) use ($jobId) {
                $q->select('source_hash')
                    ->from('scrape_items')
                    ->where('job_id', $jobId)
                    ->whereNotNull('source_hash');
            });
        }

        $chapters = $query->get(['id', 'chapter_number', 'sub_chapter', 'title', 'scrape_hash']);

        if ($chapters->isEmpty()) {
            $this->components->warn('No scraped chapters found for this story.');
            return self::FAILURE;
        }

        $scrapeHashes = $chapters->pluck('scrape_hash')->unique()->filter()->values()->toArray();

        $sortOrderQuery = DB::table('scrape_items')
            ->whereIn('source_hash', $scrapeHashes)
            ->select('source_hash', 'sort_order', 'job_id');

        if ($jobId) {
            $sortOrderQuery->where('job_id', $jobId);
        }

        $sortOrderMap = $sortOrderQuery->get()->keyBy('source_hash');

        $plan = [];
        foreach ($chapters as $chapter) {
            $scrapeInfo = $sortOrderMap->get($chapter->scrape_hash);
            $plan[] = [
                'chapter' => $chapter,
                'original_sort_order' => $scrapeInfo?->sort_order,
            ];
        }

        usort($plan, fn ($a, $b) =>
            ($a['original_sort_order'] ?? PHP_INT_MAX) <=> ($b['original_sort_order'] ?? PHP_INT_MAX)
        );

        $changes = [];
        $newNumber = $offset;

        foreach ($plan as $entry) {
            $chapter = $entry['chapter'];
            $newNumber++;
            $newNumberStr = (string) $newNumber;

            if ($chapter->chapter_number !== $newNumberStr) {
                $changes[] = [
                    'id' => $chapter->id,
                    'title' => $chapter->title ?: '(no title)',
                    'old_number' => $chapter->chapter_number,
                    'new_number' => $newNumberStr,
                    'sort_order' => $entry['original_sort_order'],
                ];
            }
        }

        $this->components->info("Story #{$storyId}: {$chapters->count()} scraped chapters, {$newNumber} total (offset: {$offset})");

        if (empty($changes)) {
            $this->components->info('All chapter numbers are already correct. Nothing to fix.');
            return self::SUCCESS;
        }

        return $this->previewAndApply($storyId, $changes, $isDryRun, ['ID', 'Title', 'Old #', 'New #', 'Sort Order']);
    }

    /**
     * Mode 3: Parse chapter number from title, reorder by parsed number.
     *
     * Handles scattered misplaced chapters automatically by reading the
     * intended chapter number from the title (e.g., "Chương 50: Tiêu đề").
     *
     * Steps:
     * 1. Parse number from each chapter's title
     * 2. Sort by parsed number (stable sort preserves order for unparseable titles)
     * 3. Use .5 decimal trick to reposition via chapter_number update
     * 4. Run resequence to clean up to integers
     */
    private function handleByTitle(): int
    {
        $storyId = (int) $this->argument('story_id');
        $isDryRun = $this->option('dry-run');

        $chapters = Chapter::where('story_id', $storyId)
            ->orderBy('sort_key')
            ->orderBy('sub_chapter')
            ->get(['id', 'chapter_number', 'sub_chapter', 'sort_key', 'title']);

        if ($chapters->isEmpty()) {
            $this->components->warn('No chapters found for this story.');
            return self::FAILURE;
        }

        // Parse intended number from title
        $parsed = $chapters->map(function ($ch) {
            $titleNumber = self::parseChapterNumberFromTitle($ch->title);
            return [
                'id' => $ch->id,
                'chapter_number' => $ch->chapter_number,
                'sub_chapter' => $ch->sub_chapter,
                'sort_key' => (float) $ch->sort_key,
                'title' => $ch->title,
                'title_number' => $titleNumber,
            ];
        });

        $withNumber = $parsed->whereNotNull('title_number');
        $withoutNumber = $parsed->whereNull('title_number');

        if ($withNumber->isEmpty()) {
            $this->components->error('Could not parse chapter number from any title. Titles must contain "Chương X" or "Chapter X" pattern.');
            return self::FAILURE;
        }

        $this->components->info("Parsed chapter numbers from {$withNumber->count()}/{$chapters->count()} titles.");

        if ($withoutNumber->isNotEmpty()) {
            $this->components->warn("{$withoutNumber->count()} chapters have unparseable titles (will keep current position).");
        }

        // Find chapters whose parsed title number differs from current chapter_number
        $misplaced = $withNumber->filter(fn ($ch) =>
            (string) $ch['title_number'] !== $ch['chapter_number']
        );

        if ($misplaced->isEmpty()) {
            $this->components->info('All parseable chapters already have correct numbers. Nothing to fix.');
            return self::SUCCESS;
        }

        $this->components->warn("{$misplaced->count()} chapters need repositioning:");
        $this->newLine();

        $previewRows = $misplaced->map(fn ($ch) => [
            $ch['id'],
            \Illuminate\Support\Str::limit($ch['title'], 40),
            $ch['chapter_number'],
            (string) $ch['title_number'],
            (string) $ch['sort_key'],
        ])->values()->toArray();

        $this->table(
            ['ID', 'Title', 'Current #', 'Parsed #', 'Sort Key'],
            array_slice($previewRows, 0, 50)
        );

        if (count($previewRows) > 50) {
            $this->components->info('... and ' . (count($previewRows) - 50) . ' more rows');
        }

        if ($isDryRun) {
            $this->components->info('DRY RUN — no changes applied.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Reposition these chapters and resequence?')) {
            $this->components->info('Cancelled.');
            return self::SUCCESS;
        }

        // Apply moves using .5 decimal trick
        $prefix = DB::getTablePrefix();
        foreach ($misplaced as $ch) {
            $tempNumber = ($ch['title_number'] - 1) . '.5';
            DB::statement(
                "UPDATE {$prefix}chapters SET chapter_number = ? WHERE id = ?",
                [$tempNumber, $ch['id']]
            );
        }

        $this->components->info("Repositioned {$misplaced->count()} chapters. Running resequence...");
        $this->newLine();

        // Now resequence to clean up
        return $this->handleResequence();
    }

    /**
     * Parse chapter number from title string.
     *
     * Supports patterns:
     * - "Chương 50: Tiêu đề"       → 50
     * - "Chương 50 - Tiêu đề"      → 50
     * - "Chương 50"                 → 50
     * - "Chapter 50"                → 50
     * - "Ch.50"                     → 50
     * - "Ch 50"                     → 50
     * - "Hồi 50"                    → 50
     * - "Q.50" / "Quyển 50"        → 50
     *
     * Returns null if no pattern matches.
     */
    private static function parseChapterNumberFromTitle(?string $title): ?int
    {
        if (! $title) {
            return null;
        }

        // Vietnamese + English chapter patterns
        $patterns = [
            '/^ch(?:ương|apter|\.|\s)\s*(\d+)/iu',  // Chương/Chapter/Ch./Ch + number
            '/^hồi\s+(\d+)/iu',                      // Hồi + number
            '/^quyển\s+(\d+)/iu',                     // Quyển + number
            '/^q\.\s*(\d+)/iu',                       // Q. + number
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($title), $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Mode 2: Re-number ALL chapters 1,2,3... based on current sort_key order.
     * No scrape data needed.
     */
    private function handleResequence(): int
    {
        $storyId = (int) $this->argument('story_id');
        $offset = (int) $this->option('offset');
        $isDryRun = $this->option('dry-run');

        $chapters = Chapter::where('story_id', $storyId)
            ->orderBy('sort_key')
            ->orderBy('sub_chapter')
            ->get(['id', 'chapter_number', 'sub_chapter', 'sort_key', 'title']);

        if ($chapters->isEmpty()) {
            $this->components->warn('No chapters found for this story.');
            return self::FAILURE;
        }

        $changes = [];
        $newNumber = $offset;

        foreach ($chapters as $chapter) {
            // sub_chapter > 0 shares the same chapter_number as its parent
            if ($chapter->sub_chapter > 0) {
                $newNumberStr = (string) $newNumber; // same as previous main chapter
            } else {
                $newNumber++;
                $newNumberStr = (string) $newNumber;
            }

            if ($chapter->chapter_number !== $newNumberStr) {
                $changes[] = [
                    'id' => $chapter->id,
                    'title' => $chapter->title ?: '(no title)',
                    'old_number' => $chapter->chapter_number,
                    'new_number' => $newNumberStr,
                    'sort_key' => (string) $chapter->sort_key,
                    'sub' => $chapter->sub_chapter,
                ];
            }
        }

        $this->components->info("Story #{$storyId}: {$chapters->count()} chapters (resequence mode, offset: {$offset})");

        if (empty($changes)) {
            $this->components->info('All chapter numbers are already sequential. Nothing to fix.');
            return self::SUCCESS;
        }

        return $this->previewAndApply($storyId, $changes, $isDryRun, ['ID', 'Title', 'Old #', 'New #', 'Sort Key', 'Sub']);
    }

    /**
     * Show preview table, confirm, and apply changes.
     */
    private function previewAndApply(int $storyId, array $changes, bool $isDryRun, array $headers): int
    {
        $this->components->warn(count($changes) . ' chapters need re-numbering:');
        $this->newLine();

        $previewRows = collect($changes)->map(fn ($c) => array_map(
            fn ($key) => $key === 'title' ? \Illuminate\Support\Str::limit($c[$key], 40) : ($c[$key] ?? 'N/A'),
            array_keys($c)
        ))->toArray();

        $this->table($headers, array_slice($previewRows, 0, 50));

        if (count($previewRows) > 50) {
            $this->components->info('... and ' . (count($previewRows) - 50) . ' more rows (hidden)');
        }

        if ($isDryRun) {
            $this->components->info('DRY RUN — no changes applied.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?')) {
            $this->components->info('Cancelled.');
            return self::SUCCESS;
        }

        // 2-phase update to avoid unique constraint conflicts
        DB::transaction(function () use ($changes) {
            $ids = [];
            $phase1Cases = [];
            $phase2Cases = [];

            foreach ($changes as $change) {
                $ids[] = $change['id'];
                $phase1Cases[] = "WHEN id = {$change['id']} THEN '_tmp_{$change['id']}'";
                $phase2Cases[] = "WHEN id = {$change['id']} THEN '{$change['new_number']}'";
            }

            $idsList = implode(',', $ids);
            $prefix = DB::getTablePrefix();

            DB::statement("
                UPDATE {$prefix}chapters
                SET chapter_number = CASE {$this->buildCaseSql($phase1Cases)} ELSE chapter_number END
                WHERE id IN ({$idsList})
            ");

            DB::statement("
                UPDATE {$prefix}chapters
                SET chapter_number = CASE {$this->buildCaseSql($phase2Cases)} ELSE chapter_number END
                WHERE id IN ({$idsList})
            ");
        });

        RebuildChapterNavigation::dispatch($storyId);

        $this->components->info('Done. ' . count($changes) . ' chapters re-numbered.');
        $this->components->info('Navigation rebuild dispatched for story #' . $storyId);

        return self::SUCCESS;
    }

    private function buildCaseSql(array $cases): string
    {
        return implode(' ', $cases);
    }
}

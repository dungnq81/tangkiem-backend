<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\table;

class ScrapeBatchCreate extends Command
{
    protected $signature = 'scrape:batch-chapters
        {template : Path to template JSON file (relative to storage/app or absolute)}
        {input    : Path to input TXT file or directory (relative to storage/app or absolute)}
        {--dry-run : Preview only, do not create jobs}';

    protected $description = 'Create multiple scrape jobs from a template config + input file';

    /**
     * Fields from template that are used to create each ScrapeJob.
     * Anything not in this list is ignored (safety measure).
     */
    private const TEMPLATE_FIELDS = [
        'source_id',
        'entity_type',
        'selectors',
        'ai_prompt',
        'pagination',
        'detail_config',
        'import_defaults',
        'is_scheduled',
        'auto_import',
        'schedule_frequency',
        'schedule_time',
        'schedule_day_of_week',
        'schedule_day_of_month',
    ];

    public function handle(): int
    {
        $templatePath = $this->resolveFilePath($this->argument('template'));
        $inputPath = $this->resolveFilePath($this->argument('input'));

        // ─── Validate files ──────────────────────────────────────────
        if (!File::exists($templatePath)) {
            error("Template file not found: {$templatePath}");
            return self::FAILURE;
        }

        if (!File::exists($inputPath)) {
            error("Input not found: {$inputPath}");
            return self::FAILURE;
        }

        // ─── Parse template ──────────────────────────────────────────
        $templateRaw = json_decode(File::get($templatePath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error('Invalid JSON in template file: ' . json_last_error_msg());
            return self::FAILURE;
        }

        // Only keep allowed fields
        $template = array_intersect_key($templateRaw, array_flip(self::TEMPLATE_FIELDS));

        // Validate source exists
        $sourceId = $template['source_id'] ?? null;
        $source = $sourceId ? ScrapeSource::find($sourceId) : null;
        if (!$source) {
            error("Source ID {$sourceId} not found in database.");
            return self::FAILURE;
        }

        info("📋 Template: {$source->name} | Entity: {$template['entity_type']}");

        // ─── Resolve input files (file or directory) ─────────────────
        $inputFiles = $this->resolveInputFiles($inputPath);

        if (empty($inputFiles)) {
            warning('No .txt files found in input path.');
            return self::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            warning('🔍 DRY RUN — no jobs will be created.');
        }

        info('📂 Processing ' . count($inputFiles) . ' file(s)...');

        // ─── Process each file ───────────────────────────────────────
        $totalCreated = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        foreach ($inputFiles as $file) {
            $relativePath = str_replace(
                storage_path('app') . DIRECTORY_SEPARATOR,
                '',
                $file,
            );
            $this->newLine();
            info("📄 {$relativePath}");

            $entries = $this->parseInputFile($file);

            if (empty($entries)) {
                warning('   (trống hoặc chỉ có comment)');
                continue;
            }

            [$created, $failed, $skipped] = $this->processEntries(
                $entries, $template, $isDryRun,
            );

            $totalCreated += $created;
            $totalFailed += $failed;
            $totalSkipped += $skipped;
        }

        // ─── Grand total ─────────────────────────────────────────────
        $this->newLine();
        $verb = $isDryRun ? 'Would create' : 'Created';
        info("📊 TOTAL — {$verb}: {$totalCreated} | Failed: {$totalFailed} | Skipped: {$totalSkipped}");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve input path to a list of .txt files.
     * If path is a file, return it as a single-element array.
     * If path is a directory, recursively find all .txt files.
     *
     * @return string[]
     */
    private function resolveInputFiles(string $path): array
    {
        if (File::isFile($path)) {
            return [$path];
        }

        if (File::isDirectory($path)) {
            $files = File::allFiles($path);

            return collect($files)
                ->filter(fn ($f) => $f->getExtension() === 'txt')
                ->map(fn ($f) => $f->getPathname())
                ->sort()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Process a batch of entries from one input file.
     *
     * @return array{int, int, int} [created, failed, skipped]
     */
    private function processEntries(
        array $entries,
        array $template,
        bool $isDryRun,
    ): array {
        $results = [];
        $created = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($entries as $lineNum => $entry) {
            $storyName = $entry['name'];
            $targetUrl = $entry['url'];
            $storyId = $entry['story_id'] ?? null;

            // Resolve parent_story_id
            if ($storyId) {
                $story = Story::find($storyId);
                if (!$story) {
                    $results[] = [$storyName, $targetUrl, $storyId ?: '—', "⏭ Skipped (story ID #{$storyId} chưa có trong DB)"];
                    $skipped++;
                    continue;
                }
            } else {
                $story = $this->resolveStory($storyName, $targetUrl);

                if (!$story) {
                    $results[] = [$storyName, $targetUrl, '—', "⏭ Skipped (chưa có trong DB)"];
                    $skipped++;
                    continue;
                }

                $storyId = $story->id;
            }

            // Skip if a job with the same target_url already exists
            if (ScrapeJob::where('target_url', $targetUrl)->exists()) {
                $results[] = [$storyName, $targetUrl, (string) $storyId, '⏭ Skipped (đã có tác vụ)'];
                $skipped++;
                continue;
            }

            // Build job data
            $jobData = array_merge($template, [
                'name' => $storyName,
                'target_url' => $targetUrl,
                'parent_story_id' => $storyId,
                'status' => ScrapeJob::STATUS_DRAFT,
                'created_by' => 1, // admin
            ]);

            if ($isDryRun) {
                $results[] = [$storyName, $targetUrl, (string) $storyId, '🔍 Would create'];
                $created++;
            } else {
                try {
                    $job = ScrapeJob::create($jobData);
                    $results[] = [$storyName, $targetUrl, (string) $storyId, "✅ Created #{$job->id}"];
                    $created++;
                } catch (\Throwable $e) {
                    $results[] = [$storyName, $targetUrl, (string) $storyId, "❌ {$e->getMessage()}"];
                    $failed++;
                }
            }
        }

        // Per-file table
        if (!empty($results)) {
            table(
                ['Truyện', 'URL', 'Story ID', 'Status'],
                $results,
            );

            $verb = $isDryRun ? 'Would create' : 'Created';
            info("   {$verb}: {$created} | Failed: {$failed} | Skipped: {$skipped}");
        }

        return [$created, $failed, $skipped];
    }

    /**
     * Parse the input TXT file into an array of entries.
     * Format: Name | URL | StoryID (optional)
     *
     * @return array<int, array{name: string, url: string, story_id: ?int}>
     */
    private function parseInputFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 2) {
                warning("Line " . ($lineNum + 1) . " skipped (need at least Name | URL): {$line}");
                continue;
            }

            $entry = [
                'name' => $parts[0],
                'url' => $parts[1],
            ];

            if (isset($parts[2]) && $parts[2] !== '') {
                $entry['story_id'] = (int) $parts[2];
            }

            $entries[$lineNum + 1] = $entry;
        }

        return $entries;
    }

    /**
     * Resolve file path: if absolute, use as-is; otherwise relative to storage/app.
     */
    private function resolveFilePath(string $path): string
    {
        // Absolute path: starts with drive letter (Windows) or / (Unix)
        if (preg_match('#^([a-zA-Z]:\\\\|/)#', $path)) {
            return $path;
        }

        return storage_path("app/{$path}");
    }

    /**
     * Find the correct Story by title, using scrape_url to disambiguate
     * when multiple stories share the same name (e.g. different authors).
     */
    private function resolveStory(string $storyName, string $targetUrl): ?Story
    {
        // Exact title match
        /** @var \Illuminate\Database\Eloquent\Collection<int, Story> $stories */
        $stories = Story::where('title', $storyName)->get();

        // Fallback: partial match (escape LIKE wildcards per database.md §4)
        if ($stories->isEmpty()) {
            $escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $storyName);
            /** @var \Illuminate\Database\Eloquent\Collection<int, Story> $stories */
            $stories = Story::where('title', 'like', "%{$escaped}%")->get();
        }

        if ($stories->isEmpty()) {
            return null;
        }

        // Only one match → return it
        if ($stories->count() === 1) {
            return $stories->first();
        }

        // Multiple matches → disambiguate using scrape_url
        // Compare URL paths to find the best match
        $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?? '';

        foreach ($stories as $story) {
            if (!$story->scrape_url) {
                continue;
            }

            $storyPath = parse_url($story->scrape_url, PHP_URL_PATH) ?? '';

            // Check if URL paths share common segments (e.g. /co-long/ vs /vo-danh/)
            if ($storyPath && $targetPath && str_contains($targetPath, $storyPath)) {
                return $story;
            }

            // Check if they share the same parent path (author slug)
            $targetSegments = explode('/', trim($targetPath, '/'));
            $storySegments = explode('/', trim($storyPath, '/'));

            // Match if author segment (2nd segment) is the same
            // e.g. /kiem-hiep/co-long/tieu-sat-tinh/ → co-long
            if (count($targetSegments) >= 2 && count($storySegments) >= 2
                && $targetSegments[1] === $storySegments[1]) {
                return $story;
            }
        }

        // No URL match found → return first (best effort)
        return $stories->first();
    }
}

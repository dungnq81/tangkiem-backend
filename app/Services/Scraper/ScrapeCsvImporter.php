<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Models\Category;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Models\Story;
use Awcodes\Curator\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bulk import stories + chapter scrape jobs from a pipe-delimited CSV file.
 *
 * CSV Format (delimiter = |):
 *   story_title|chapter_url|pagination_pattern|cover_image_url
 *
 * Reuses config from a "template" ScrapeJob (selectors, detail_config, import_defaults, etc.)
 * and creates one ScrapeJob per CSV row, auto-creating Story records when missing.
 */
class ScrapeCsvImporter
{
    /**
     * Fields to clone from the template ScrapeJob to each new job.
     */
    private const TEMPLATE_FIELDS = [
        'source_id',
        'entity_type',
        'selectors',
        'ai_prompt',
        'detail_config',
        'import_defaults',
    ];

    /**
     * All recognized CSV column headers.
     */
    private const ALL_COLUMNS = ['story_title', 'chapter_url', 'pagination_pattern', 'cover_image_url'];

    // ═══════════════════════════════════════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Import stories + scrape jobs from a CSV file.
     *
     * @param  string       $csvPath        Absolute path to the CSV file
     * @param  ScrapeSource $source         The scrape source
     * @param  ScrapeJob    $templateJob    Template job to clone config from
     * @param  array        $storyDefaults  Batch-wide defaults for new stories:
     *                                      {origin, status, is_published, category_ids, primary_category_id}
     * @param  bool         $dryRun         Preview only, don't create anything
     * @return BatchImportResult
     */
    public function import(
        string $csvPath,
        ScrapeSource $source,
        ScrapeJob $templateJob,
        array $storyDefaults = [],
        bool $dryRun = false,
    ): BatchImportResult {
        $result = new BatchImportResult();

        // Validate template job
        if ($templateJob->entity_type !== ScrapeJob::ENTITY_CHAPTER) {
            $result->addError(0, 'Template', 'Template job phải có entity_type = chapter');

            return $result;
        }

        if ((int) $templateJob->source_id !== (int) $source->id) {
            $result->addError(0, 'Template', 'Template job phải cùng nguồn đã chọn');

            return $result;
        }

        // Parse CSV
        $rows = $this->parseCsv($csvPath);

        if (empty($rows)) {
            $result->addError(0, 'CSV', 'File CSV trống hoặc không hợp lệ');

            return $result;
        }

        // Extract template config
        $templateConfig = $this->extractTemplateConfig($templateJob);

        // Process each row
        foreach ($rows as $lineNum => $row) {
            $this->processRow($lineNum, $row, $source, $templateConfig, $storyDefaults, $dryRun, $result);
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CSV Parsing
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Parse a pipe-delimited CSV file into rows.
     *
     * @return array<int, array{story_title: string, chapter_url: string, pagination_pattern: ?string, cover_image_url: ?string}>
     */
    public function parseCsv(string $csvPath): array
    {
        $content = file_get_contents($csvPath);

        if ($content === false) {
            return [];
        }

        // Remove UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $rows = [];
        $hasHeader = false;

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            // Detect and skip header row
            if (! $hasHeader && $this->isHeaderRow($parts)) {
                $hasHeader = true;

                continue;
            }

            if (count($parts) < 2) {
                Log::warning("CSV import: Line {$lineNum} skipped (need at least story_title|chapter_url)", [
                    'line' => $line,
                ]);

                continue;
            }

            // Validate story_title
            $storyTitle = $parts[0];
            if (empty($storyTitle)) {
                Log::warning("CSV import: Line {$lineNum} skipped (empty story_title)");

                continue;
            }

            // Validate chapter_url
            $chapterUrl = $parts[1];
            if (empty($chapterUrl) || ! filter_var($chapterUrl, FILTER_VALIDATE_URL)) {
                Log::warning("CSV import: Line {$lineNum} skipped (invalid chapter_url: {$chapterUrl})");

                continue;
            }

            $rows[$lineNum + 1] = [
                'story_title'       => $storyTitle,
                'chapter_url'       => $chapterUrl,
                'pagination_pattern' => ! empty($parts[2]) ? $parts[2] : null,
                'cover_image_url'   => ! empty($parts[3]) ? $parts[3] : null,
            ];
        }

        return $rows;
    }

    /**
     * Check if a row looks like a header (contains known column names).
     */
    private function isHeaderRow(array $parts): bool
    {
        $normalized = array_map(fn ($p) => Str::slug($p, '_'), $parts);

        return count(array_intersect($normalized, self::ALL_COLUMNS)) >= 2;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Row Processing
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process a single CSV row: create/match Story + create ScrapeJob.
     */
    private function processRow(
        int $lineNum,
        array $row,
        ScrapeSource $source,
        array $templateConfig,
        array $storyDefaults,
        bool $dryRun,
        BatchImportResult $result,
    ): void {
        $storyTitle = $row['story_title'];
        $chapterUrl = $row['chapter_url'];

        // ─── Dedup: skip if ScrapeJob already exists for this target_url ──
        if (ScrapeJob::where('target_url', $chapterUrl)->exists()) {
            $result->addSkipped($lineNum, $storyTitle, $chapterUrl, 'Đã có tác vụ cho URL này');

            return;
        }

        // ─── Match or create Story ───────────────────────────────────────
        $scrapeHash = ScrapeItem::hashUrl($chapterUrl);

        if ($dryRun) {
            $existingStory = $this->findExistingStory($storyTitle, $scrapeHash);
            $storyLabel = $existingStory
                ? "Story #{$existingStory->id} (có sẵn)"
                : '(sẽ tạo mới)';

            $result->addPreview($lineNum, $storyTitle, $chapterUrl, $storyLabel);

            return;
        }

        try {
            [$story, $isNewStory] = $this->resolveStory(
                $storyTitle,
                $chapterUrl,
                $scrapeHash,
                $source,
                $storyDefaults,
                $row['cover_image_url'],
            );

            // ─── Create ScrapeJob ────────────────────────────────────────
            $jobData = array_merge($templateConfig, [
                'name'            => $storyTitle,
                'target_url'      => $chapterUrl,
                'parent_story_id' => $story->id,
                'pagination'      => $this->buildPagination($row['pagination_pattern']),
                'status'          => ScrapeJob::STATUS_DRAFT,
                'created_by'      => auth()->id() ?? 1,
            ]);

            $job = ScrapeJob::create($jobData);

            $storyLabel = $isNewStory
                ? "Story #{$story->id} (mới)"
                : "Story #{$story->id} (có sẵn)";

            $result->addCreated($lineNum, $storyTitle, $chapterUrl, $storyLabel, $job->id);
        } catch (\Throwable $e) {
            Log::error('CSV import row failed', [
                'line'  => $lineNum,
                'title' => $storyTitle,
                'error' => $e->getMessage(),
            ]);

            $result->addError($lineNum, $storyTitle, $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Story Resolution
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Find or create a Story for a CSV row.
     *
     * @return array{0: Story, 1: bool} [story, isNewStory]
     */
    private function resolveStory(
        string $title,
        string $chapterUrl,
        string $scrapeHash,
        ScrapeSource $source,
        array $defaults,
        ?string $coverImageUrl,
    ): array {
        // Try to find existing story
        $existing = $this->findExistingStory($title, $scrapeHash);

        if ($existing) {
            return [$existing, false];
        }

        // Download cover image (outside any transaction — HTTP call)
        $coverImageId = null;
        if ($coverImageUrl) {
            $coverImageId = $this->downloadImage($coverImageUrl, $source->base_url);
        }

        // Create new story with minimal data + batch defaults
        $story = Story::create([
            'title'              => $title,
            'slug'               => $this->uniqueSlug($title),
            'origin'             => $defaults['origin'] ?? StoryOrigin::default()->value,
            'status'             => $defaults['status'] ?? StoryStatus::default()->value,
            'is_published'       => (bool) ($defaults['is_published'] ?? false),
            'cover_image_id'     => $coverImageId,
            'scrape_source_id'   => $source->id,
            'scrape_url'         => $chapterUrl,
            'scrape_hash'        => $scrapeHash,
        ]);

        // Attach categories if provided
        $categoryIds = $defaults['category_ids'] ?? [];
        if (! empty($categoryIds)) {
            $validIds = Category::whereIn('id', $categoryIds)->pluck('id')->toArray();
            if (! empty($validIds)) {
                $story->categories()->sync($validIds);

                $primaryCatId = $defaults['primary_category_id'] ?? null;
                if ($primaryCatId && in_array((int) $primaryCatId, $validIds, true)) {
                    $story->update(['primary_category_id' => (int) $primaryCatId]);
                } else {
                    $story->update(['primary_category_id' => $validIds[0]]);
                }
            }
        }

        return [$story, true];
    }

    /**
     * Find an existing story by scrape_hash (priority) or title.
     */
    private function findExistingStory(string $title, string $scrapeHash): ?Story
    {
        return Story::where('scrape_hash', $scrapeHash)
            ->orWhere('title', $title)
            ->orderByRaw("CASE WHEN scrape_hash = ? THEN 0 ELSE 1 END", [$scrapeHash])
            ->first();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extract reusable config from a template ScrapeJob.
     */
    private function extractTemplateConfig(ScrapeJob $template): array
    {
        $config = [];
        foreach (self::TEMPLATE_FIELDS as $field) {
            $config[$field] = $template->getAttribute($field);
        }

        return $config;
    }

    /**
     * Build pagination config from a URL pattern.
     */
    private function buildPagination(?string $paginationPattern): array
    {
        if (empty($paginationPattern)) {
            return ['type' => 'single'];
        }

        return [
            'type'       => 'query_param',
            'url_pattern' => $paginationPattern,
            'start_page' => 0,
            'max_pages'  => 500,
            // end_page intentionally omitted → auto-mode (scrape until empty)
        ];
    }

    /**
     * Generate a unique slug for a story.
     */
    private function uniqueSlug(string $title): string
    {
        $slug = Str::slug($title);

        if (empty($slug)) {
            $slug = 'truyen-' . Str::random(6);
        }

        $original = $slug;
        $count = 1;

        while (Story::where('slug', $slug)->exists()) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Download an image and create a Curator Media record.
     * Reuses the same logic as ScrapeImporter::downloadImage().
     */
    private function downloadImage(string $url, string $baseUrl = ''): ?int
    {
        try {
            if (! str_starts_with($url, 'http')) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }

            $response = Http::timeout(15)->get($url);

            if ($response->failed()) {
                return null;
            }

            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'scrape/' . date('Y/m') . '/' . Str::random(20) . '.' . $extension;
            $disk = 'public';

            Storage::disk($disk)->put($filename, $response->body());

            $media = Media::create([
                'disk'      => $disk,
                'directory' => dirname($filename),
                'name'      => pathinfo($filename, PATHINFO_FILENAME),
                'path'      => $filename,
                'ext'       => $extension,
                'type'      => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
                'size'      => strlen($response->body()),
            ]);

            return $media->id;
        } catch (\Throwable $e) {
            Log::warning('CSV import: Image download failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

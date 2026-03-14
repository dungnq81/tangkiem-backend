<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Jobs\RebuildChapterNavigation;
use App\Models\Author;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\Story;
use Awcodes\Curator\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScrapeImporter
{
    /**
     * Import selected items from a job.
     */
    public function importSelected(ScrapeJob $job): array
    {
        $items = $job->items()
            ->where('status', ScrapeItem::STATUS_SELECTED)
            ->orderBy('sort_order')
            ->get();

        $results = ['imported' => 0, 'merged' => 0, 'errors' => 0, 'skipped' => 0];

        // Track story IDs that need navigation rebuild (for chapter imports)
        $storyIdsNeedingNavRebuild = [];
        $isChapterJob = in_array($job->entity_type, [ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL]);

        foreach ($items as $item) {
            try {
                // Skip if already imported (dedup via scrape_hash)
                if ($this->isAlreadyImported($item, $job->entity_type)) {
                    $item->update(['status' => ScrapeItem::STATUS_SKIPPED]);
                    $results['skipped']++;
                    continue;
                }

                // For chapter batch: suppress observer's navigation dispatch
                // (we'll rebuild once after the loop instead of N times)
                if ($isChapterJob) {
                    Chapter::withoutEvents(function () use ($item, $job, &$results, &$storyIdsNeedingNavRebuild) {
                        $outcome = $this->importItem($item, $job);

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
                    $outcome = $this->importItem($item, $job);

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
            // Batch import done, but still have draft items for next scheduled run
            $job->markScraped();
        }

        return $results;
    }

    /**
     * Check dedup directly — avoids N+1 by accepting entity_type as a parameter.
     * Respects soft deletes: deleted records don't block re-import.
     *
     * Note: 'chapter' and 'category' are excluded — they handle smart merge
     * internally in their respective import methods.
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
     * Import a single item based on entity type.
     *
     * @return string 'imported' or 'merged'
     */
    public function importItem(ScrapeItem $item, ScrapeJob $job): string
    {
        return match ($job->entity_type) {
            ScrapeJob::ENTITY_CATEGORY => $this->importCategory($item, $job),
            ScrapeJob::ENTITY_AUTHOR => $this->importAuthor($item, $job),
            ScrapeJob::ENTITY_STORY => $this->importStory($item, $job),
            ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL => $this->importChapter($item, $job),
        };
    }

    /**
     * Import category — slug-based dedup with smart merge.
     *
     * @return string 'imported' or 'merged'
     */
    protected function importCategory(ScrapeItem $item, ScrapeJob $job): string
    {
        $data = $item->raw_data;
        $name = trim($data['name'] ?? $data['title'] ?? '');

        if (empty($name)) {
            throw new \RuntimeException('Category name is empty');
        }

        $slug = Str::slug($name);
        $existing = Category::where('slug', $slug)->first();

        if ($existing) {
            // Smart merge: only fill empty fields
            $this->smartMerge($existing, [
                'description' => $data['description'] ?? null,
            ]);

            return 'merged';
        }

        Category::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'scrape_source_id' => $job->source_id,
            'scrape_url' => $item->source_url,
            'scrape_hash' => $item->source_hash,
        ]);

        return 'imported';
    }

    /**
     * Import author — slug-based dedup with smart merge.
     * Auto-downloads avatar if provided in scraped data.
     *
     * @return string 'imported' or 'merged'
     */
    protected function importAuthor(ScrapeItem $item, ScrapeJob $job): string
    {
        $data = $item->raw_data;
        $name = trim($data['name'] ?? $data['title'] ?? '');

        if (empty($name)) {
            throw new \RuntimeException('Author name is empty');
        }

        $slug = Str::slug($name);
        $existing = Author::where('slug', $slug)->first();

        if ($existing) {
            // Smart merge: only fill empty fields
            $mergeData = [
                'bio' => $data['bio'] ?? $data['description'] ?? null,
                'original_name' => $data['original_name'] ?? null,
            ];

            $this->smartMerge($existing, $mergeData);

            return 'merged';
        }

        // Download avatar from scraped data (if provided)
        $avatarUrl = $data['avatar'] ?? $data['avatar_url'] ?? $data['image'] ?? null;
        $avatarId = $avatarUrl
            ? $this->downloadImage($avatarUrl, $job->source->base_url)
            : null;

        Author::create([
            'name' => $name,
            'slug' => $slug,
            'bio' => $data['bio'] ?? $data['description'] ?? null,
            'avatar_id' => $avatarId,
            'is_active' => true,
            'scrape_source_id' => $job->source_id,
            'scrape_url' => $item->source_url,
            'scrape_hash' => $item->source_hash,
        ]);

        return 'imported';
    }

    /**
     * Import story — match author & categories by name (case-insensitive).
     * Uses import_defaults from the job for type, origin, status, fallback author.
     * Image download happens OUTSIDE the DB transaction for safety.
     */
    protected function importStory(ScrapeItem $item, ScrapeJob $job): string
    {
        $data = $item->raw_data;
        $title = trim($data['title'] ?? '');

        if (empty($title)) {
            throw new \RuntimeException('Story title is empty');
        }

        $defaults = $job->import_defaults ?? [];

        // ------------------------------------------------------------------
        // 1) Download cover BEFORE the transaction (HTTP call should never
        //    hold a DB transaction lock — could take 15s on timeout)
        // ------------------------------------------------------------------
        $coverImageId = null;
        $coverUrl = $data['cover'] ?? $data['cover_url'] ?? $data['image'] ?? null;
        if ($coverUrl) {
            $coverImageId = $this->downloadImage($coverUrl, $job->source->base_url);
        }

        // ------------------------------------------------------------------
        // 2) Match author — cascade: scraped name → auto-match → defaults → null
        // ------------------------------------------------------------------
        $authorId = $this->resolveAuthorId($data, $defaults);

        // ------------------------------------------------------------------
        // 3) Resolve origin / status from defaults → enum defaults
        // ------------------------------------------------------------------
        $storyOrigin = $defaults['story_origin'] ?? StoryOrigin::default()->value;
        $storyStatus = $defaults['story_status'] ?? StoryStatus::default()->value;
        $isPublished = (bool) ($defaults['is_published'] ?? false);

        // ------------------------------------------------------------------
        // 4) DB transaction — fast, no external I/O
        // ------------------------------------------------------------------
        DB::beginTransaction();

        try {
            $story = Story::create([
                'title' => $title,
                'slug' => $this->uniqueSlug($title, Story::class),
                'description' => $data['description'] ?? null,
                'author_id' => $authorId,
                'cover_image_id' => $coverImageId,
                'origin' => $storyOrigin,
                'status' => $storyStatus,
                'is_published' => $isPublished,
                'is_featured' => ! empty($defaults['is_featured']),
                'is_hot' => ! empty($defaults['is_hot']),
                'is_vip' => ! empty($defaults['is_vip']),
                'scrape_source_id' => $job->source_id,
                'scrape_url' => $item->source_url,
                'scrape_hash' => $item->source_hash,
            ]);

            // Match categories — cascade: import_defaults (if set) → scraped data → none
            $categoryIds = [];
            $defaultCatIds = $defaults['category_ids'] ?? [];

            if (! empty($defaultCatIds)) {
                // Step 1: Explicit categories from import defaults → use directly
                $categoryIds = Category::whereIn('id', $defaultCatIds)->pluck('id')->toArray();
            } else {
                // Step 2: Auto-match from scraped category names (only when no explicit selection)
                $catNames = $data['categories'] ?? $data['category_names'] ?? null;
                if ($catNames) {
                    if (is_string($catNames)) {
                        $catNames = array_map('trim', explode(',', $catNames));
                    }
                    $catSlugs = array_map(fn ($name) => Str::slug($name), $catNames);
                    $categoryIds = Category::whereIn('slug', $catSlugs)->pluck('id')->toArray();
                }
            }

            if (! empty($categoryIds)) {
                $story->categories()->sync($categoryIds);

                // Primary category: import_defaults → first matched category
                $primaryCatId = $defaults['primary_category_id'] ?? null;
                if ($primaryCatId && Category::where('id', $primaryCatId)->exists()) {
                    $story->update(['primary_category_id' => (int) $primaryCatId]);
                } else {
                    $story->update(['primary_category_id' => $categoryIds[0]]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return 'imported';
    }

    /**
     * Resolve author ID with cascade:
     * 1) Explicit import_defaults.author_id (if set) → use directly
     * 2) Auto-match from scraped author name → match by slug/name
     * 3) No match → null
     */
    protected function resolveAuthorId(array $data, array $defaults): ?int
    {
        // Step 1: Explicit author selected in import defaults → use directly
        $explicitId = $defaults['author_id'] ?? null;
        if ($explicitId && Author::where('id', $explicitId)->exists()) {
            return (int) $explicitId;
        }

        // Step 2: Auto-match from scraped data (only when no explicit author set)
        $authorName = trim($data['author'] ?? $data['author_name'] ?? '');
        if ($authorName) {
            $authorSlug = Str::slug($authorName);
            $author = Author::where('slug', $authorSlug)->first()
                ?? Author::whereRaw('LOWER(name) = ?', [mb_strtolower($authorName)])->first();

            if ($author) {
                return $author->id;
            }
        }

        // Step 3: No match
        return null;
    }

    /**
     * Import chapter — must have parent_story_id on the job.
     *
     * Supports data from:
     * - Phase 1 (TOC): title, url, chapter_number (from title regex)
     * - Phase 2 (Detail): content, title, chapter_number, volume_number
     *
     * Smart merge: if a chapter with the same scrape_hash already exists,
     * updates content/title (similar to category merge behavior).
     */
    protected function importChapter(ScrapeItem $item, ScrapeJob $job): string
    {
        $data = $item->raw_data;
        $title = trim($data['title'] ?? '');
        $storyId = $job->parent_story_id;

        if (! $storyId) {
            throw new \RuntimeException('Chapter import requires parent_story_id on the job');
        }

        $defaults = $job->import_defaults ?? [];

        // Load story once (used for SEO metadata in both merge and create paths)
        $story = Story::find($storyId);

        if (! $story) {
            throw new \RuntimeException("Story #{$storyId} not found — may have been deleted");
        }

        $storyTitle = $story->title;

        // Extract chapter number: prefer detail page value > title regex
        $chapterNumber = $data['chapter_number'] ?? null;
        if ($chapterNumber === null) {
            $chapterNumber = $this->extractChapterNumber($title);
        }

        // Volume/part support — only set when scraped data explicitly provides it
        // null = not provided → let DB default or existing value remain
        $volumeNumber = isset($data['volume_number']) ? (int) $data['volume_number'] : null;

        // Sub-chapter support (e.g., 15.1, 15.2)
        // null = not provided → let DB default or existing value remain
        $subChapter = isset($data['sub_chapter']) ? (int) $data['sub_chapter'] : null;

        // Publish setting from import defaults (default: false)
        $isPublished = (bool) ($defaults['is_published'] ?? false);

        // Smart merge: check if chapter with same scrape_hash exists
        $existing = Chapter::where('scrape_hash', $item->source_hash)
            ->where('story_id', $storyId)
            ->first();

        if ($existing) {
            // Merge path: wrap in transaction to ensure Chapter + ChapterContent
            // are updated atomically. A crash between them would leave stale data.
            return DB::transaction(function () use ($existing, $title, $volumeNumber, $subChapter, $data, $storyTitle, $chapterNumber) {
                // Accumulate all updates to flush in a single UPDATE query
                $pendingUpdates = [];

                // Smart merge: update fields only where current value is null/empty
                $mergeData = array_filter([
                    'title' => $title ?: null,
                    'volume_number' => $volumeNumber,
                    'sub_chapter' => $subChapter,
                ], fn ($v) => $v !== null);

                foreach ($mergeData as $field => $value) {
                    if ($value !== '' && empty($existing->{$field})) {
                        $pendingUpdates[$field] = $value;
                    }
                }

                // Always update content if new content is available (overwrite)
                $content = $data['content'] ?? null;
                if ($content) {
                    $content = $this->normalizeContentLineBreaks($content);
                    $existingContent = $existing->content;
                    if ($existingContent) {
                        $existingContent->update(['content' => $content]);
                    } else {
                        ChapterContent::create([
                            'chapter_id' => $existing->id,
                            'content' => $content,
                        ]);
                    }

                    $plainText = trim(strip_tags($content));
                    $singleLine = preg_replace('/\s+/', ' ', $plainText);
                    $pendingUpdates['word_count'] = Chapter::countWords($plainText);

                    // Fill SEO fields if empty
                    if (empty($existing->meta_description)) {
                        $pendingUpdates['meta_description'] = Str::limit($singleLine, 155, '...');
                    }
                }

                // Fill meta_title if empty
                if (empty($existing->meta_title)) {
                    $pendingUpdates['meta_title'] = $title
                        ? Str::limit("{$title} - {$storyTitle}", 60, '')
                        : Str::limit("Chương {$chapterNumber} - {$storyTitle}", 60, '');
                }

                // Single UPDATE query for all accumulated changes
                if (! empty($pendingUpdates)) {
                    $existing->update($pendingUpdates);
                }

                return 'merged';
            });
        }

        // Create path: wrap in transaction to ensure Chapter + ChapterContent
        // are created atomically. Without this, a crash after Chapter::create
        // but before ChapterContent::create leaves an orphan chapter with no content.
        $normalizedNumber = Chapter::normalizeChapterNumber($chapterNumber);

        // Auto-resolve sub_chapter for duplicate chapter_numbers
        // e.g., "Chương 103 (1)", "Chương 103 (2)" → sub_chapter 0, 1, 2...
        if ($subChapter === null || $subChapter === 0) {
            $existingSub = Chapter::where('story_id', $storyId)
                ->where('chapter_number', $normalizedNumber)
                ->max('sub_chapter');

            if ($existingSub !== null) {
                $subChapter = $existingSub + 1;
            } else {
                $subChapter = 0; // first chapter with this number
            }
        }

        return DB::transaction(function () use (
            $storyId, $title, $normalizedNumber, $volumeNumber, $subChapter,
            $isPublished, $storyTitle, $data, $job, $item, $chapterNumber,
        ) {
            // Auto-generate SEO metadata
            $metaTitle = $title
                ? Str::limit("{$title} - {$storyTitle}", 60, '')
                : Str::limit("Chương {$chapterNumber} - {$storyTitle}", 60, '');
            $metaDescription = null;

            $chapter = Chapter::create([
                'story_id' => $storyId,
                'title' => $title,
                'slug' => $this->uniqueSlug($title ?: "chuong-{$chapterNumber}", Chapter::class, 'slug', ['story_id' => $storyId]),
                'chapter_number' => $normalizedNumber,
                'volume_number' => $volumeNumber,
                'sub_chapter' => $subChapter ?? 0,
                'is_published' => $isPublished,
                'published_at' => $isPublished ? now() : null,
                'meta_title' => $metaTitle,
                'scrape_source_id' => $job->source_id,
                'scrape_url' => $item->source_url,
                'scrape_hash' => $item->source_hash,
            ]);

            // If raw_data has content (from Phase 2), save it + generate meta_description
            $content = $data['content'] ?? null;
            if ($content) {
                $content = $this->normalizeContentLineBreaks($content);
                ChapterContent::create([
                    'chapter_id' => $chapter->id,
                    'content' => $content,
                ]);

                $plainText = trim(strip_tags($content));
                $singleLine = preg_replace('/\s+/', ' ', $plainText);
                $metaDescription = Str::limit($singleLine, 155, '...');

                $chapter->update([
                    'word_count' => Chapter::countWords($plainText),
                    'meta_description' => $metaDescription,
                ]);
            }

            return 'imported';
        });
    }

    /**
     * Smart merge: update model fields only where current value is NULL/empty.
     * Never overwrites existing data — safe for cross-source dedup.
     */
    protected function smartMerge(Model $model, array $newData): void
    {
        $updates = [];

        foreach ($newData as $field => $value) {
            if ($value !== null && $value !== '' && empty($model->{$field})) {
                $updates[$field] = $value;
            }
        }

        if (! empty($updates)) {
            $model->update($updates);
        }
    }

    /**
     * Download image, create Curator Media record, return media ID.
     */
    protected function downloadImage(string $url, string $baseUrl = ''): ?int
    {
        try {
            // Resolve relative URL
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

            // Create Curator Media record so the image is usable in Filament
            $media = Media::create([
                'disk' => $disk,
                'directory' => dirname($filename),
                'name' => pathinfo($filename, PATHINFO_FILENAME),
                'path' => $filename,
                'ext' => $extension,
                'type' => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
                'size' => strlen($response->body()),
            ]);

            return $media->id;
        } catch (\Throwable $e) {
            Log::warning('Image download failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }


    /**
     * Generate unique slug with optional scope (e.g., chapter slugs unique per story).
     */
    protected function uniqueSlug(string $title, string $modelClass, string $column = 'slug', array $scope = []): string
    {
        $slug = Str::slug($title);

        // Empty slug fallback
        if (empty($slug)) {
            $slug = 'item-' . Str::random(6);
        }

        $original = $slug;
        $count = 1;

        while ($modelClass::where($column, $slug)->where($scope)->exists()) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Extract chapter number from title.
     * E.g., "Chương 15: Tên chương" → "15", "Hồi 001" → "1"
     */
    protected function extractChapterNumber(string $title): string
    {
        if (preg_match('/(?:ch(?:ương|ap|apter)?|hồi)\s*(\d+(?:\.\d+)?[a-zA-Z]?)/iu', $title, $matches)) {
            return Chapter::normalizeChapterNumber($matches[1]);
        }

        // Fallback: find first number
        if (preg_match('/(\d+(?:\.\d+)?[a-zA-Z]?)/', $title, $matches)) {
            return Chapter::normalizeChapterNumber($matches[1]);
        }

        return '0';
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

    /**
     * Normalize plain-text content to HTML by converting newlines to <br> tags.
     *
     * Sources like tangthuvien.vn serve chapter content as plain text with \n
     * instead of HTML block tags (<p>, <div>, <br>). Without conversion,
     * line breaks are lost when rendered in a browser.
     *
     * Only converts when no block-level HTML tags are detected.
     */
    protected function normalizeContentLineBreaks(string $content): string
    {
        // If content already has HTML block tags, leave it as-is
        if (preg_match('/<(p|div|br)\b/i', $content)) {
            return $content;
        }

        // Convert \n to <br> for plain-text content
        return nl2br($content, false);
    }
}

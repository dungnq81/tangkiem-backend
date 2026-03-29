<?php

declare(strict_types=1);

namespace App\Services\Scraper\Importers;

use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\Story;
use App\Services\Scraper\Contracts\EntityImporterInterface;
use App\Support\SeoLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
class ChapterImporter extends BaseImporter implements EntityImporterInterface
{
    public function import(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string
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

        // Sequential numbering mode: override chapter_number with counter
        // Used for volume-based stories where chapters restart per volume
        if ($sequentialNumber !== null) {
            $chapterNumber = (string) $sequentialNumber;
        } else {
            // Extract chapter number: prefer detail page value > title regex
            $chapterNumber = $data['chapter_number'] ?? null;
            if ($chapterNumber === null) {
                $chapterNumber = $this->extractChapterNumber($title);
            }
        }

        // Volume/part support — only set when scraped data explicitly provides it
        $volumeNumber = isset($data['volume_number']) ? (int) $data['volume_number'] : null;

        // Sub-chapter support (e.g., 15.1, 15.2)
        $subChapter = isset($data['sub_chapter']) ? (int) $data['sub_chapter'] : null;

        // Publish setting from import defaults (default: false)
        $isPublished = (bool) ($defaults['is_published'] ?? false);

        // Smart merge: check if chapter with same scrape_hash exists
        $existing = Chapter::where('scrape_hash', $item->source_hash)
            ->where('story_id', $storyId)
            ->first();

        if ($existing) {
            return $this->mergeExisting($existing, $title, $volumeNumber, $subChapter, $data, $storyTitle, $chapterNumber);
        }

        return $this->createNew(
            $storyId, $title, $chapterNumber, $volumeNumber, $subChapter,
            $isPublished, $storyTitle, $data, $job, $item,
        );
    }

    /**
     * Merge path: update existing chapter with new data.
     */
    protected function mergeExisting(
        Chapter $existing,
        string $title,
        ?int $volumeNumber,
        ?int $subChapter,
        array $data,
        string $storyTitle,
        string $chapterNumber,
    ): string {
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
                    $pendingUpdates['meta_description'] = Str::limit($singleLine, SeoLimits::MAX_DESCRIPTION, '');
                }
            }

            // Fill meta_title if empty
            if (empty($existing->meta_title)) {
                $pendingUpdates['meta_title'] = $title
                    ? Str::limit("{$title} - {$storyTitle}", SeoLimits::MAX_TITLE, '')
                    : Str::limit("Chương {$chapterNumber} - {$storyTitle}", SeoLimits::MAX_TITLE, '');
            }

            // Single UPDATE query for all accumulated changes
            if (! empty($pendingUpdates)) {
                $existing->update($pendingUpdates);
            }

            return 'merged';
        });
    }

    /**
     * Create path: new chapter + content in a transaction.
     */
    protected function createNew(
        int $storyId,
        string $title,
        string $chapterNumber,
        ?int $volumeNumber,
        ?int $subChapter,
        bool $isPublished,
        string $storyTitle,
        array $data,
        ScrapeJob $job,
        ScrapeItem $item,
    ): string {
        $normalizedNumber = Chapter::normalizeChapterNumber($chapterNumber);

        // Auto-resolve sub_chapter for duplicate chapter_numbers
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
                ? Str::limit("{$title} - {$storyTitle}", SeoLimits::MAX_TITLE, '')
                : Str::limit("Chương {$chapterNumber} - {$storyTitle}", SeoLimits::MAX_TITLE, '');

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
                $metaDescription = Str::limit($singleLine, SeoLimits::MAX_DESCRIPTION, '');

                $chapter->update([
                    'word_count' => Chapter::countWords($plainText),
                    'meta_description' => $metaDescription,
                ]);
            }

            return 'imported';
        });
    }
}

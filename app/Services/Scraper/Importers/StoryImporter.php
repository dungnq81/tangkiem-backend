<?php

declare(strict_types=1);

namespace App\Services\Scraper\Importers;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Models\Author;
use App\Models\Category;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\Story;
use App\Services\Scraper\Contracts\EntityImporterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import story — match author & categories by name (case-insensitive).
 * Uses import_defaults from the job for type, origin, status, fallback author.
 * Image download happens OUTSIDE the DB transaction for safety.
 */
class StoryImporter extends BaseImporter implements EntityImporterInterface
{
    public function import(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string
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
}

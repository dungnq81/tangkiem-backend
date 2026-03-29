<?php

declare(strict_types=1);

namespace App\Services\Scraper\Importers;

use App\Models\Chapter;
use Awcodes\Curator\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Base class for entity importers — shared utilities.
 *
 * Provides: smart merge, unique slug generation, image download,
 * content normalization.
 */
abstract class BaseImporter
{
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
     * Normalize plain-text content to HTML by converting newlines to <br> tags.
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

<?php

declare(strict_types=1);

namespace App\Services\Scraper\Importers;

use App\Models\Author;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\Contracts\EntityImporterInterface;
use Illuminate\Support\Str;

/**
 * Import author — slug-based dedup with smart merge.
 * Auto-downloads avatar if provided in scraped data.
 */
class AuthorImporter extends BaseImporter implements EntityImporterInterface
{
    public function import(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string
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
}

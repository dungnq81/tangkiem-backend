<?php

declare(strict_types=1);

namespace App\Services\Scraper\Importers;

use App\Models\Category;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\Contracts\EntityImporterInterface;
use Illuminate\Support\Str;

/**
 * Import category — slug-based dedup with smart merge.
 */
class CategoryImporter extends BaseImporter implements EntityImporterInterface
{
    public function import(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string
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
}

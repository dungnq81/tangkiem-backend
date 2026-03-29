<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;

/**
 * Interface for entity-specific importers.
 *
 * Each entity type (category, author, story, chapter) implements
 * this interface to handle its own import/merge logic.
 */
interface EntityImporterInterface
{
    /**
     * Import a single scrape item into the target entity.
     *
     * @param  int|null  $sequentialNumber  Override chapter_number (sequential numbering mode)
     * @return string 'imported' or 'merged'
     */
    public function import(ScrapeItem $item, ScrapeJob $job, ?int $sequentialNumber = null): string;
}

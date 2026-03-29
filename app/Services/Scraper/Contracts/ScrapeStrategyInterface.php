<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Models\ScrapeJob;

/**
 * Strategy interface for scraping modes.
 *
 * Each scraping mode (TOC listing, single chapter detail, chain crawl)
 * implements this interface and receives the ScraperService as a
 * dependency to access shared infrastructure (drivers, pipeline, etc.).
 */
interface ScrapeStrategyInterface
{
    public function execute(ScrapeJob $job): void;
}

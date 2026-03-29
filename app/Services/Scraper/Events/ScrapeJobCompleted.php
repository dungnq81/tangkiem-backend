<?php

declare(strict_types=1);

namespace App\Services\Scraper\Events;

use App\Models\ScrapeJob;
use App\Services\Scraper\Data\ScrapeMetrics;

class ScrapeJobCompleted
{
    public function __construct(
        public readonly ScrapeJob $job,
        public readonly ScrapeMetrics $metrics,
    ) {}
}

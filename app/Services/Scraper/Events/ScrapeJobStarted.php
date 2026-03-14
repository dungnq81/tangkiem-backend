<?php

declare(strict_types=1);

namespace App\Services\Scraper\Events;

use App\Models\ScrapeJob;

class ScrapeJobStarted
{
    public function __construct(
        public readonly ScrapeJob $job,
        public readonly string $phase = 'toc',
    ) {}
}

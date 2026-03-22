<?php

declare(strict_types=1);

namespace App\Services\Scraper\Events;

use App\Models\ScrapeJob;

class DetailFetchCompleted
{
    public function __construct(
        public readonly ScrapeJob $job,
        public readonly int $fetched,
        public readonly int $errors,
        public readonly int $remaining,
    ) {}
}

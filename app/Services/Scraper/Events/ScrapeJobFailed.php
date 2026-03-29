<?php

declare(strict_types=1);

namespace App\Services\Scraper\Events;

use App\Models\ScrapeJob;

class ScrapeJobFailed
{
    public function __construct(
        public readonly ScrapeJob $job,
        public readonly string $error,
        public readonly ?\Throwable $exception = null,
    ) {}
}

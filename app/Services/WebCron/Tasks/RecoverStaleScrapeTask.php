<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class RecoverStaleScrapeTask extends AbstractTask
{
    /** Run at most every 15 minutes. */
    protected int $throttleSeconds = 900;

    public function name(): string
    {
        return 'scrape:recover-stale';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('scrape:recover-stale');
    }
}

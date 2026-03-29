<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class DispatchScrapeTask extends AbstractTask
{
    public function name(): string
    {
        return 'scrape:run-scheduled';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('scrape:run-scheduled');
    }
}

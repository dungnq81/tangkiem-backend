<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class AggregateAnalyticsTask extends AbstractTask
{
    /** Run at most every 5 minutes. */
    protected int $throttleSeconds = 300;

    public function name(): string
    {
        return 'analytics:aggregate';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('analytics:aggregate');
    }
}

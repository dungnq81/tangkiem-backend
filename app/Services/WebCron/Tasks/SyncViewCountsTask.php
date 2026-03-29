<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class SyncViewCountsTask extends AbstractTask
{
    /** Run at most every 2 minutes. */
    protected int $throttleSeconds = 120;

    public function name(): string
    {
        return 'views:sync';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('views:sync');
    }
}

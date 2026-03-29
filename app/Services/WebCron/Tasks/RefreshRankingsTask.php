<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class RefreshRankingsTask extends AbstractTask
{
    /** Run at most every 30 minutes. */
    protected int $throttleSeconds = 1800;

    public function name(): string
    {
        return 'rankings:refresh';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('rankings:refresh');
    }
}

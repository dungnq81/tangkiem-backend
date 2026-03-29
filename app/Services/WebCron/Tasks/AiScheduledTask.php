<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class AiScheduledTask extends AbstractTask
{
    protected array $suppressOutput = [
        'No AI tasks were due or no stories/authors to process.',
    ];

    public function name(): string
    {
        return 'ai:run-scheduled';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('ai:run-scheduled');
    }
}

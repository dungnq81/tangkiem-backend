<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class PublishChaptersTask extends AbstractTask
{
    protected array $suppressOutput = [
        'No scheduled chapters are due for publishing.',
    ];

    public function name(): string
    {
        return 'chapters:publish-scheduled';
    }

    public function execute(): ?string
    {
        return $this->runArtisan('chapters:publish-scheduled');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

class ProcessQueueTask extends AbstractTask
{
    public function name(): string
    {
        return 'queue:work';
    }

    public function execute(): ?string
    {
        // Use --once: process exactly 1 job then exit.
        // DO NOT use --stop-when-empty — it blocks the PHP-FPM worker
        // until the queue is empty, which can take minutes/hours for
        // large scrape jobs, causing cascading FPM worker exhaustion
        // when WebCron pings every 2 minutes.
        //
        // Remaining jobs are picked up by subsequent WebCron cycles.
        $this->runArtisan('queue:work', [
            '--once'    => true,
            '--timeout' => 300,
            '--memory'  => 512,
            '--quiet'   => true,
        ]);

        // Queue worker output is not useful — always silent
        return null;
    }
}

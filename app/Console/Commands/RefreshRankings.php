<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cache\RankingService;
use Illuminate\Console\Command;

class RefreshRankings extends Command
{
    protected $signature = 'rankings:refresh';

    protected $description = 'Refresh story rankings cache';

    public function handle(RankingService $rankingService): int
    {
        $this->info('Refreshing rankings...');

        $rankingService->refreshRankings();

        $this->info('✅ Rankings cache refreshed!');

        return Command::SUCCESS;
    }
}

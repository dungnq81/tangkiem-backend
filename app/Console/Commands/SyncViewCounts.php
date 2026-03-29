<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cache\ViewCountService;
use Illuminate\Console\Command;

class SyncViewCounts extends Command
{
    protected $signature = 'views:sync';

    protected $description = 'Sync buffered view counts from cache to database';

    public function handle(ViewCountService $viewService): int
    {
        $this->info('Syncing view counts...');

        $stats = $viewService->syncToDatabase();

        $this->info('✅ Synced:');
        $this->info("   - Stories: {$stats['stories']}");
        $this->info("   - Chapters: {$stats['chapters']}");
        $this->info("   - Total views: {$stats['total_views']}");

        return Command::SUCCESS;
    }
}

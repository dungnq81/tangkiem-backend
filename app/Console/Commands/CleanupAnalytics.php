<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Console\Command;

class CleanupAnalytics extends Command
{
    protected $signature = 'analytics:cleanup
                            {--days= : Delete page_visits older than N days. Defaults to config value.}';

    protected $description = 'Delete old page_visits records to free disk space';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        $days = $this->option('days')
            ? (int) $this->option('days')
            : null;

        $effectiveDays = $days ?? config('analytics.retention.raw_days', 30);
        $this->info("🗑️  Cleaning up page_visits older than {$effectiveDays} days...");

        $deleted = $aggregator->cleanupOldVisits($days);
        $this->info("   ✅ Deleted {$deleted} old visit records");

        return Command::SUCCESS;
    }
}

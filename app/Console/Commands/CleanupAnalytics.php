<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyAnalytic;
use App\Models\PageVisit;
use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Console\Command;

class CleanupAnalytics extends Command
{
    protected $signature = 'analytics:cleanup
                            {--days= : Delete page_visits older than N days. Defaults to config value.}
                            {--purge-ip= : Delete ALL page_visits from a specific IP and re-aggregate.}
                            {--reset : Delete ALL analytics data and start fresh.}';

    protected $description = 'Delete old analytics records (page_visits + daily_analytics) to free disk space';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        // Reset everything (if requested)
        if ($this->option('reset')) {
            return $this->resetAll();
        }

        // Purge specific IP (if requested)
        if ($ip = $this->option('purge-ip')) {
            return $this->purgeIp($ip, $aggregator);
        }

        // 1. Cleanup raw page_visits
        $rawDays = $this->option('days')
            ? (int) $this->option('days')
            : null;

        $effectiveRawDays = $rawDays ?? config('analytics.retention.raw_days', 30);
        $this->info("🗑️  Cleaning up page_visits older than {$effectiveRawDays} days...");

        $deletedVisits = $aggregator->cleanupOldVisits($rawDays);
        $this->info("   ✅ Deleted {$deletedVisits} page_visit records");

        // 2. Cleanup aggregated daily_analytics
        $aggDays = config('analytics.retention.aggregated_days', 180);
        $this->info("🗑️  Cleaning up daily_analytics older than {$aggDays} days...");

        $deletedAggregates = $aggregator->cleanupOldAggregates();
        $this->info("   ✅ Deleted {$deletedAggregates} daily_analytics records");

        return Command::SUCCESS;
    }

    /**
     * Delete all visits from a specific IP and re-aggregate affected dates.
     */
    private function purgeIp(string $ip, AnalyticsAggregator $aggregator): int
    {
        $count = PageVisit::where('ip_address', $ip)->count();

        if ($count === 0) {
            $this->info("No visits found from IP {$ip}");

            return Command::SUCCESS;
        }

        $this->info("🔍 Found {$count} visits from IP {$ip}");

        if (! $this->confirm("Delete all {$count} records and re-aggregate stats?")) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        // Delete in chunks
        $deleted = 0;
        do {
            $batch = PageVisit::where('ip_address', $ip)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("   ✅ Deleted {$deleted} page_visit records from {$ip}");

        // Re-aggregate last 7 days
        $this->info('   📊 Re-aggregating daily stats...');
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $n = $aggregator->aggregateDailyStats($date);
            $this->line("   📅 {$date}: {$n} rows");
        }

        $this->info('   ✅ Dashboard stats recalculated');

        return Command::SUCCESS;
    }

    /**
     * Delete ALL analytics data and start fresh.
     */
    private function resetAll(): int
    {
        $visitCount = PageVisit::count();
        $dailyCount = DailyAnalytic::count();

        $this->warn("⚠️  This will delete ALL analytics data:");
        $this->line("   page_visits: {$visitCount} records");
        $this->line("   daily_analytics: {$dailyCount} records");

        if (! $this->confirm('Are you sure? This cannot be undone.')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        PageVisit::truncate();
        DailyAnalytic::truncate();

        $this->info('   ✅ All analytics data deleted. Starting fresh.');

        return Command::SUCCESS;
    }
}

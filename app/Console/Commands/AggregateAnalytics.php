<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Console\Command;

class AggregateAnalytics extends Command
{
    protected $signature = 'analytics:aggregate
                            {--date= : Aggregate for a specific date (Y-m-d). Defaults to today.}';

    protected $description = 'Flush Redis analytics buffer and aggregate into daily statistics';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        $this->info('📊 Running analytics aggregation...');

        // Stage 1: Flush Redis → page_visits
        $flushed = $aggregator->flushBufferToDatabase();
        $this->info("   ✅ Flushed {$flushed} visits from Redis → page_visits");

        // Stage 2: Aggregate page_visits → daily_analytics
        $date = $this->option('date');
        $aggregated = $aggregator->aggregateDailyStats($date);
        $dateLabel = $date ?? now()->toDateString();
        $this->info("   ✅ Aggregated {$aggregated} daily_analytics rows for {$dateLabel}");

        return Command::SUCCESS;
    }
}

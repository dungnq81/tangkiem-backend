<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Console\Command;

class AggregateAnalytics extends Command
{
    protected $signature = 'analytics:aggregate
                            {--date= : Aggregate for a specific date (Y-m-d). Defaults to today.}
                            {--days= : Re-aggregate last N days (e.g. --days=7)}
                            {--refresh : Shortcut for --days=7. Use after deploying aggregation logic changes.}';

    protected $description = 'Flush Redis analytics buffer and aggregate into daily statistics';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        $this->info('📊 Running analytics aggregation...');

        // Stage 1: Flush Redis → page_visits
        $flushed = $aggregator->flushBufferToDatabase();
        $this->info("   ✅ Flushed {$flushed} visits from Redis → page_visits");

        // Stage 2: Aggregate page_visits → daily_analytics
        $days = $this->option('refresh') ? 7 : (int) $this->option('days');

        if ($days > 0) {
            // Re-aggregate multiple days (useful after code changes)
            $totalAggregated = 0;
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $n = $aggregator->aggregateDailyStats($date);
                $this->line("   📅 {$date}: {$n} rows");
                $totalAggregated += $n;
            }
            $this->info("   ✅ Re-aggregated {$totalAggregated} rows over {$days} days");
        } else {
            $date = $this->option('date');
            $aggregated = $aggregator->aggregateDailyStats($date);
            $dateLabel = $date ?? now()->toDateString();
            $this->info("   ✅ Aggregated {$aggregated} daily_analytics rows for {$dateLabel}");
        }

        return Command::SUCCESS;
    }
}

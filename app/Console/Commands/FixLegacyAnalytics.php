<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyAnalytic;
use App\Models\PageVisit;
use App\Services\Analytics\AnalyticsAggregator;
use App\Services\Analytics\Collector\VisitorParser;
use Illuminate\Console\Command;

/**
 * Fix legacy browser/OS values in page_visits, then re-aggregate ALL dates.
 *
 * Step 1: Fix Unknown/NULL browser & OS values in page_visits.
 * Step 2: Re-aggregate daily_analytics for ALL dates that have page_visits data.
 *
 * Safe to run multiple times (idempotent).
 * Can be removed after running once on production.
 */
class FixLegacyAnalytics extends Command
{
    protected $signature = 'analytics:fix-legacy
                            {--diagnose : Show what browser/OS values are currently stored (read-only)}
                            {--days=90 : Number of days back to re-aggregate (default: 90)}';

    protected $description = 'Fix browser/OS values in page_visits and re-aggregate ALL past daily_analytics';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        if ($this->option('diagnose')) {
            $this->diagnose();
            return self::SUCCESS;
        }

        $this->fixLegacyValues();
        $this->reaggregateDailyAnalytics($aggregator);

        $this->info('✅ Done!');
        return self::SUCCESS;
    }

    /**
     * Diagnostic: show current browser/OS distribution in page_visits
     * and sample browser_breakdown JSON from daily_analytics.
     *
     * This helps identify WHY values are wrong.
     */
    private function diagnose(): void
    {
        $this->info('🔍 Diagnosing browser/OS data...');
        $this->newLine();

        // 1. page_visits: browser distribution
        $this->info('═══ page_visits: Browser Distribution (top 15, human only) ═══');
        $browsers = PageVisit::query()
            ->where('is_bot', false)
            ->selectRaw('COALESCE(browser, "NULL") as browser_name, COUNT(*) as cnt')
            ->groupBy('browser')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'browser_name');

        if ($browsers->isEmpty()) {
            $this->warn('   No page_visits data found.');
        } else {
            $this->table(['Browser', 'Count'], $browsers->map(fn ($cnt, $name) => [$name, number_format($cnt)])->values()->toArray());
        }

        $this->newLine();

        // 2. page_visits: OS distribution
        $this->info('═══ page_visits: OS Distribution (top 15, human only) ═══');
        $oses = PageVisit::query()
            ->where('is_bot', false)
            ->selectRaw('COALESCE(os, "NULL") as os_name, COUNT(*) as cnt')
            ->groupBy('os')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'os_name');

        if ($oses->isEmpty()) {
            $this->warn('   No page_visits data found.');
        } else {
            $this->table(['OS', 'Count'], $oses->map(fn ($cnt, $name) => [$name, number_format($cnt)])->values()->toArray());
        }

        $this->newLine();

        // 3. daily_analytics: sample browser_breakdown JSON
        $this->info('═══ daily_analytics: Latest browser_breakdown JSON (last 3 days) ═══');
        $recentBreakdowns = DailyAnalytic::query()
            ->siteWide()
            ->whereNotNull('browser_breakdown')
            ->orderByDesc('date')
            ->limit(3)
            ->get(['date', 'browser_breakdown', 'os_breakdown']);

        if ($recentBreakdowns->isEmpty()) {
            $this->warn('   No daily_analytics with browser_breakdown found.');
        } else {
            foreach ($recentBreakdowns as $row) {
                $this->info("   📅 {$row->date->format('Y-m-d')}");
                $this->line('   Browsers: ' . json_encode($row->browser_breakdown, JSON_UNESCAPED_UNICODE));
                $this->line('   OS:       ' . json_encode($row->os_breakdown, JSON_UNESCAPED_UNICODE));
                $this->newLine();
            }
        }

        // 4. Show counts of problematic values
        $this->info('═══ Problem Summary ═══');
        $unknownBrowser = PageVisit::query()
            ->where('is_bot', false)
            ->where(fn ($q) => $q->where('browser', 'Unknown')
                ->orWhere('browser', 'Khác')
                ->orWhere('browser', '')
                ->orWhereNull('browser'))
            ->count();
        $totalHuman = PageVisit::query()->where('is_bot', false)->count();

        $this->line("   Total human visits: " . number_format($totalHuman));
        $this->line("   Unknown/Khác/NULL browser: " . number_format($unknownBrowser) . " (" . ($totalHuman > 0 ? round($unknownBrowser / $totalHuman * 100, 1) : 0) . "%)");
    }

    /**
     * Fix legacy Unknown/NULL/empty → 'Khác' in page_visits.
     */
    private function fixLegacyValues(): void
    {
        $this->info('🔧 Fixing legacy browser/OS values in page_visits...');

        $browserUpdated = PageVisit::query()
            ->where(function ($q) {
                $q->where('browser', 'Unknown')
                    ->orWhereNull('browser')
                    ->orWhere('browser', '');
            })
            ->update(['browser' => 'Khác']);

        $this->info("   → Browser: {$browserUpdated} rows updated");

        $osUpdated = PageVisit::query()
            ->where(function ($q) {
                $q->where('os', 'Unknown')
                    ->orWhereNull('os')
                    ->orWhere('os', '');
            })
            ->update(['os' => 'Khác']);

        $this->info("   → OS: {$osUpdated} rows updated");
    }

    /**
     * Re-aggregate daily_analytics for ALL dates with page_visits data.
     *
     * This regenerates the browser_breakdown and os_breakdown JSON columns
     * from the (now-corrected) page_visits data, fixing stale aggregations.
     */
    private function reaggregateDailyAnalytics(AnalyticsAggregator $aggregator): void
    {
        $days = (int) $this->option('days');

        // Find all distinct dates in page_visits within the date range
        $cutoff = now()->subDays($days)->toDateString();
        $dates = PageVisit::query()
            ->where('visited_at', '>=', $cutoff . ' 00:00:00')
            ->selectRaw('DATE(visited_at) as visit_date')
            ->distinct()
            ->orderBy('visit_date')
            ->pluck('visit_date')
            ->map(fn ($d) => (string) $d)
            ->unique()
            ->values();

        $total = $dates->count();
        $this->info("📊 Re-aggregating daily_analytics for {$total} dates (last {$days} days)...");

        if ($total === 0) {
            $this->warn('   No page_visits found in the date range.');
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $totalUpserted = 0;
        foreach ($dates as $date) {
            $upserted = $aggregator->aggregateDailyStats($date);
            $totalUpserted += $upserted;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ Re-aggregated {$totalUpserted} daily_analytics rows across {$total} dates");
    }
}

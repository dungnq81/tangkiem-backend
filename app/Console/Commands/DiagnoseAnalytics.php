<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyAnalytic;
use App\Models\PageVisit;
use App\Services\Analytics\AnalyticsAggregator;
use App\Services\Analytics\AnalyticsCollector;
use App\Services\Analytics\Collector\RedisBuffer;
use App\Services\Analytics\Data\VisitData;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Diagnose analytics pipeline — check each stage for issues.
 *
 * Checks:
 * 1. Config (analytics.enabled)
 * 2. Redis buffer (pending visits)
 * 3. page_visits table (raw data)
 * 4. daily_analytics table (aggregated data)
 * 5. Schedule locks (withoutOverlapping)
 * 6. WebCron locks
 *
 * With --fix: clears stuck locks and forces a flush+aggregate cycle.
 * With --test: simulates a visit to test the full pipeline end-to-end.
 */
class DiagnoseAnalytics extends Command
{
    protected $signature = 'analytics:diagnose
                            {--fix : Clear stuck locks and force flush+aggregate}
                            {--test : Push a test visit through the full pipeline}';

    protected $description = 'Diagnose analytics pipeline issues and optionally fix them';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        $this->info('🔍 Diagnosing Analytics Pipeline...');
        $this->newLine();

        $hasIssues = false;

        // ── 1. Config ────────────────────────────────────────────────
        $enabled = config('analytics.enabled', true);
        $this->line('1️⃣  Config analytics.enabled: ' . ($enabled ? '✅ true' : '❌ false'));
        if (! $enabled) {
            $this->error('   → Analytics is DISABLED. Set ANALYTICS_ENABLED=true in .env');
            $hasIssues = true;
        }

        // ── 2. Redis Buffer ──────────────────────────────────────────
        $buffer = app(RedisBuffer::class);
        $bufferSize = $buffer->size();
        $this->line("2️⃣  Redis buffer size: {$bufferSize}");
        if ($bufferSize > 0) {
            $this->warn("   → {$bufferSize} visits waiting in Redis (not yet flushed to DB)");
        }

        // Check Redis connection
        try {
            Redis::ping();
            $this->line('   Redis connection: ✅ OK');
        } catch (\Throwable $e) {
            $this->error('   Redis connection: ❌ FAILED — ' . $e->getMessage());
            $hasIssues = true;
        }

        // ── 3. page_visits ───────────────────────────────────────────
        $totalVisits = PageVisit::count();
        $todayVisits = PageVisit::whereBetween('visited_at', [
            now()->toDateString() . ' 00:00:00',
            now()->toDateString() . ' 23:59:59',
        ])->count();
        $this->line("3️⃣  page_visits total: {$totalVisits} | today: {$todayVisits}");
        if ($totalVisits === 0) {
            $this->warn('   → No raw visits in DB. Either buffer not flushed, or recently reset.');
        }

        // ── 4. daily_analytics ───────────────────────────────────────
        $totalDaily = DailyAnalytic::count();
        $todayDaily = DailyAnalytic::where('date', now()->toDateString())->count();
        $this->line("4️⃣  daily_analytics total: {$totalDaily} | today: {$todayDaily}");
        if ($totalDaily === 0) {
            $this->warn('   → No aggregated data. Aggregation may not have run since last reset.');
        }

        // ── 5. Schedule locks (withoutOverlapping) ───────────────────
        // Laravel uses cache key: framework/schedule-HASH
        $aggregateLockKey = 'framework/schedule-' . sha1('analytics:aggregate');
        $hasAggregateLock = Cache::has($aggregateLockKey);
        $this->line('5️⃣  Schedule lock (analytics:aggregate): ' . ($hasAggregateLock ? '🔒 LOCKED' : '🔓 unlocked'));
        if ($hasAggregateLock) {
            $this->warn('   → Schedule lock is active! aggregate command cannot run via scheduler.');
            $hasIssues = true;
        }

        // ── 6. WebCron locks ─────────────────────────────────────────
        $webCronLock = Cache::has('web_cron:running');
        $webCronThrottle = Cache::has('web_cron:last_check');
        $taskThrottle = Cache::has('web_cron:task:analytics_aggregate');
        $this->line('6️⃣  WebCron lock: ' . ($webCronLock ? '🔒 LOCKED' : '🔓 unlocked'));
        $this->line('   WebCron throttle: ' . ($webCronThrottle ? '⏳ throttled' : '✅ ready'));
        $this->line('   Task throttle (analytics_aggregate): ' . ($taskThrottle ? '⏳ throttled' : '✅ ready'));
        if ($webCronLock) {
            $this->warn('   → WebCron worker lock is stuck! No tasks can execute.');
            $hasIssues = true;
        }

        $this->newLine();

        // ── Summary ──────────────────────────────────────────────────
        if ($hasIssues) {
            $this->error('⚠️  Issues detected!');
            if (! $this->option('fix')) {
                $this->info('   Run with --fix to auto-repair: php artisan analytics:diagnose --fix');
            }
        } else {
            $this->info('✅ No obvious issues found.');
            if ($totalVisits === 0 && $bufferSize === 0) {
                $this->comment('   Note: Both buffer and DB are empty. This is normal right after a reset.');
                $this->comment('   New visits will accumulate as users browse the site.');
            }
        }

        // ── Fix mode ─────────────────────────────────────────────────
        if ($this->option('fix')) {
            $this->newLine();
            $this->info('🔧 Running fixes...');

            // Clear all locks
            Cache::forget($aggregateLockKey);
            Cache::forget('web_cron:running');
            Cache::forget('web_cron:last_check');
            Cache::forget('web_cron:task:analytics_aggregate');
            $this->line('   ✅ Cleared all locks and throttles');

            // Force flush + aggregate
            $this->line('   📊 Flushing Redis buffer...');
            $flushed = $aggregator->flushBufferToDatabase();
            $this->line("   ✅ Flushed {$flushed} visits from Redis → page_visits");

            $this->line('   📊 Aggregating last 7 days...');
            $totalAggregated = 0;
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $n = $aggregator->aggregateDailyStats($date);
                $this->line("   📅 {$date}: {$n} rows");
                $totalAggregated += $n;
            }
            $this->line("   ✅ Aggregated {$totalAggregated} daily_analytics rows");

            // Show final state
            $this->newLine();
            $this->info('📊 Final state:');
            $this->line('   page_visits: ' . PageVisit::count());
            $this->line('   daily_analytics: ' . DailyAnalytic::count());
            $this->line('   Redis buffer: ' . $buffer->size());
        }

        // ── Test mode — full pipeline test ───────────────────────────
        if ($this->option('test')) {
            $this->newLine();
            $this->info('🧪 Testing full pipeline end-to-end...');

            // Step 1: Test RedisBuffer push directly
            $this->line('   Step 1: Push test visit to Redis buffer...');
            try {
                $testVisit = new VisitData(
                    visitedAt: now()->toDateTimeString(),
                    sessionHash: 'test_diag_hash_',
                    pageType: 'story',
                    pageId: null,
                    deviceType: 'desktop',
                    browser: 'DiagnosticTest',
                    os: 'CLI',
                    referrerType: 'direct',
                    referrerDomain: null,
                    isBot: false,
                    countryCode: null,
                    apiDomainId: null,
                    meta: [
                        'page_slug' => '_diagnostic_test',
                        'ip_hash' => 'test_ip_hash____',
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'DiagnosticTest/1.0',
                    ],
                );

                $pushed = $buffer->push($testVisit);
                $this->line('   → Redis push: ' . ($pushed ? '✅ OK' : '❌ FAILED (buffer full?)'));

                if (! $pushed) {
                    return Command::SUCCESS;
                }

                $newSize = $buffer->size();
                $this->line("   → Buffer size after push: {$newSize}");
            } catch (\Throwable $e) {
                $this->error('   → Redis push EXCEPTION: ' . $e->getMessage());
                return Command::SUCCESS;
            }

            // Step 2: Flush to page_visits
            $this->line('   Step 2: Flush buffer → page_visits...');
            try {
                $flushed = $aggregator->flushBufferToDatabase();
                $this->line("   → Flushed: {$flushed} rows");
            } catch (\Throwable $e) {
                $this->error('   → Flush EXCEPTION: ' . $e->getMessage());
                $this->error('   → This is likely why visits are NOT being recorded!');
                return Command::SUCCESS;
            }

            // Step 3: Verify in DB
            $testRow = PageVisit::where('browser', 'DiagnosticTest')
                ->where('page_slug', '_diagnostic_test')
                ->first();
            $this->line('   Step 3: Verify in page_visits: ' . ($testRow ? '✅ Found (id=' . $testRow->id . ')' : '❌ NOT FOUND'));

            // Step 4: Aggregate
            $this->line('   Step 4: Aggregate today...');
            try {
                $n = $aggregator->aggregateDailyStats();
                $this->line("   → Aggregated: {$n} rows");
            } catch (\Throwable $e) {
                $this->error('   → Aggregate EXCEPTION: ' . $e->getMessage());
            }

            // Step 5: Verify daily_analytics
            $dailyRow = DailyAnalytic::where('date', now()->toDateString())
                ->whereNull('page_type')
                ->whereNull('page_id')
                ->whereNull('api_domain_id')
                ->first();
            $this->line('   Step 5: Verify daily_analytics: ' . ($dailyRow ? '✅ Found (views=' . $dailyRow->total_views . ')' : '❌ NOT FOUND'));

            // Cleanup test data
            if ($testRow) {
                $testRow->delete();
                $this->line('   🧹 Cleaned up test visit from page_visits');
                // Re-aggregate to remove test data
                $aggregator->aggregateDailyStats();
            }

            $this->newLine();

            // Step 6: Test AnalyticsCollector::track() with fake request
            $this->line('   Step 6: Test AnalyticsCollector::track() with fake request...');
            try {
                $collector = app(AnalyticsCollector::class);
                $fakeRequest = Request::create('/api/v1/stories/test-slug', 'GET');
                $fakeRequest->headers->set('User-Agent', 'DiagnosticTest/1.0');

                // Temporarily capture tracked visits
                $beforeSize = $buffer->size();
                $collector->track($fakeRequest);
                $afterSize = $buffer->size();

                if ($afterSize > $beforeSize) {
                    $this->line('   → track() pushed to buffer: ✅ OK');
                    // Cleanup: pop the test entry
                    Redis::lpop(config('analytics.buffer.key', 'analytics:visits'));
                } else {
                    $this->warn('   → track() did NOT push to buffer');
                    $this->warn('   → Possible causes:');
                    $this->warn('     1. URL pattern "/api/v1/stories/test-slug" not matching page_types config');
                    $this->warn('     2. Request IP in exclude list');
                    $this->warn('     3. Dedup key still active (same session hash within 5 min)');

                    // Debug: show what resolvePageType would do
                    $pageTypes = config('analytics.tracking.page_types', []);
                    $this->line('   → Configured page_types:');
                    foreach ($pageTypes as $type => $pattern) {
                        $matches = fnmatch($pattern, 'api/v1/stories/test-slug') ? '✅ match' : '  no match';
                        $this->line("     {$type}: {$pattern} → {$matches}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error('   → track() EXCEPTION: ' . $e->getMessage());
                $this->error('   → File: ' . $e->getFile() . ':' . $e->getLine());
                $this->error('   → This is the root cause! Tracking throws an error.');
            }

            $this->newLine();
            $this->info('🧪 Pipeline test complete.');
        }

        return Command::SUCCESS;
    }
}

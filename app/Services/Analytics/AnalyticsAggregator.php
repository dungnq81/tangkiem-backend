<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\DailyAnalytic;
use App\Models\PageVisit;
use App\Services\Analytics\Collector\RedisBuffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AnalyticsAggregator
 *
 * Two-stage aggregation pipeline:
 * 1. Flush: Redis buffer → page_visits table (batch insert)
 * 2. Aggregate: page_visits → daily_analytics (group by day)
 *
 * Designed to be idempotent — safe to re-run on overlap windows.
 *
 * Performance optimizations:
 * - Uses index-friendly whereBetween instead of whereDate (allows MySQL index scan)
 * - Session analysis (bounce rate, avg pages) computed in pure SQL subquery
 * - Returning visitors uses Eloquent subquery (no large IN clause)
 */
class AnalyticsAggregator
{
    public function __construct(
        private readonly RedisBuffer $buffer,
    ) {}

    /**
     * Run the full aggregation pipeline.
     *
     * @return array{flushed: int, aggregated: int}
     */
    public function run(): array
    {
        $flushed = $this->flushBufferToDatabase();
        $aggregated = $this->aggregateDailyStats();

        return [
            'flushed'    => $flushed,
            'aggregated' => $aggregated,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Stage 1: Redis Buffer → page_visits
    // ═══════════════════════════════════════════════════════════════

    /**
     * Flush visits from Redis buffer into page_visits table.
     *
     * Delegates to RedisBuffer::flush() for consistent config and memory control.
     * Loops until buffer is empty (flush returns items in chunks).
     *
     * @return int Number of visits inserted
     */
    public function flushBufferToDatabase(): int
    {
        $totalInserted = 0;

        do {
            $visits = $this->buffer->flush();

            if (empty($visits)) {
                break;
            }

            // Filter malformed entries
            $valid = array_filter($visits, fn (array $v) => isset($v['page_type']));

            $totalInserted += $this->insertBatch($valid);
        } while (!empty($visits));

        return $totalInserted;
    }

    /**
     * Batch insert visits into page_visits table.
     *
     * @param array<int, array<string, mixed>> $visits
     * @return int Number of rows inserted
     */
    private function insertBatch(array $visits): int
    {
        if (empty($visits)) {
            return 0;
        }

        try {
            // Only include columns that exist in the table
            $columns = [
                'visited_at', 'page_type', 'page_id', 'page_slug',
                'session_hash', 'ip_hash', 'ip_address', 'user_id',
                'referrer_domain', 'referrer_type', 'utm_source', 'utm_medium',
                'device_type', 'browser', 'os', 'country_code', 'is_bot',
                'user_agent', 'api_domain_id',
            ];

            $rows = array_map(function (array $visit) use ($columns) {
                $row = [];
                foreach ($columns as $col) {
                    $row[$col] = $visit[$col] ?? null;
                }
                // Ensure is_bot is integer for MySQL
                $row['is_bot'] = $row['is_bot'] ? 1 : 0;
                return $row;
            }, $visits);

            PageVisit::insert($rows);

            return count($rows);
        } catch (\Throwable $e) {
            Log::error('Analytics batch insert failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Stage 2: page_visits → daily_analytics
    // ═══════════════════════════════════════════════════════════════

    /**
     * Aggregate today's page_visits into daily_analytics.
     *
     * Recalculates today's stats from scratch (idempotent).
     * Uses a single SQL query per metric group for efficiency.
     *
     * @return int Number of daily_analytics rows upserted
     */
    public function aggregateDailyStats(?string $date = null): int
    {
        $date = $date ?? now()->toDateString();
        [$dayStart, $dayEnd] = $this->dayBounds($date);
        $upserted = 0;

        try {
            // Get all distinct api_domain_ids for the day (NULL = legacy/local)
            $siteIds = PageVisit::query()
                ->whereBetween('visited_at', [$dayStart, $dayEnd])
                ->select('api_domain_id')
                ->distinct()
                ->pluck('api_domain_id')
                ->push(null) // Always include global (NULL) aggregate
                ->unique();

            foreach ($siteIds as $siteId) {
                // 1) Site-wide aggregate (page_type = null, page_id = null)
                $siteWide = $this->calculateStats($date, $dayStart, $dayEnd, null, $siteId);
                // Upsert if any activity (human or bot)
                if (($siteWide['total_views'] ?? 0) > 0 || ($siteWide['bot_views'] ?? 0) > 0) {
                    $this->upsertDailyAnalytic($date, null, null, $siteId, $siteWide);
                    $upserted++;
                }

                // 2) Per page_type aggregates (page_type = 'story', page_id = null)
                $pageTypes = PageVisit::query()
                    ->whereBetween('visited_at', [$dayStart, $dayEnd])
                    ->when($siteId !== null, fn ($q) => $q->where('api_domain_id', $siteId))
                    ->when($siteId === null, fn ($q) => $q) // Global = all visits
                    ->select('page_type')
                    ->distinct()
                    ->pluck('page_type');

                foreach ($pageTypes as $pageType) {
                    $stats = $this->calculateStats($date, $dayStart, $dayEnd, $pageType, $siteId);
                    if (($stats['total_views'] ?? 0) > 0 || ($stats['bot_views'] ?? 0) > 0) {
                        $this->upsertDailyAnalytic($date, $pageType, null, $siteId, $stats);
                        $upserted++;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("Analytics aggregation failed for {$date}: " . $e->getMessage());
        }

        return $upserted;
    }

    /**
     * Calculate aggregated stats from page_visits for given date and optional type.
     *
     * Uses conditional aggregation to get all metrics in as few queries as possible.
     * All date filters use whereBetween for index-friendly scans.
     *
     * @return array<string, mixed>
     */
    private function calculateStats(
        string $date,
        string $dayStart,
        string $dayEnd,
        ?string $pageType = null,
        ?int $apiDomainId = null,
    ): array {
        // Build the base query with index-friendly date range
        $query = PageVisit::query()->whereBetween('visited_at', [$dayStart, $dayEnd]);

        if ($pageType !== null) {
            $query->where('page_type', $pageType);
        }

        // Site filter: NULL = global (all visits), integer = specific site
        if ($apiDomainId !== null) {
            $query->where('api_domain_id', $apiDomainId);
        }

        // ── Query 1: Core metrics (single query, conditional aggregation) ──
        // Human-only for total_views/unique_visitors (matches GA behavior).
        // Bot views tracked separately for monitoring.
        $core = (clone $query)
            ->selectRaw('SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as total_views')
            ->selectRaw('COUNT(DISTINCT CASE WHEN is_bot = 0 THEN session_hash END) as unique_visitors')
            ->selectRaw('SUM(CASE WHEN is_bot = 0 AND device_type = ? THEN 1 ELSE 0 END) as desktop_views', ['desktop'])
            ->selectRaw('SUM(CASE WHEN is_bot = 0 AND device_type = ? THEN 1 ELSE 0 END) as mobile_views', ['mobile'])
            ->selectRaw('SUM(CASE WHEN is_bot = 0 AND device_type = ? THEN 1 ELSE 0 END) as tablet_views', ['tablet'])
            ->selectRaw('SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_views')
            ->first();

        $humanViews = (int) ($core->total_views ?? 0);
        $botViews = (int) ($core->bot_views ?? 0);

        if ($humanViews === 0 && $botViews === 0) {
            return ['total_views' => 0];
        }

        // ── Query 2: Session analysis — pure SQL subquery (no PHP memory) ──
        // Uses derived table to calculate bounce rate and avg pages per session
        $sessionStats = $this->calculateSessionStats($query);

        // ── Query 3+4: Returning visitors (subquery, no large IN clause) ──
        $todayHashesSubquery = (clone $query)
            ->where('is_bot', false)
            ->select('session_hash')
            ->distinct();

        $todayVisitorCount = (int) $sessionStats->total_sessions;

        $returningCount = 0;
        if ($todayVisitorCount > 0) {
            $returningCount = PageVisit::query()
                ->where('visited_at', '<', $dayStart)
                ->whereIn('session_hash', $todayHashesSubquery)
                ->distinct('session_hash')
                ->count('session_hash');
        }

        $newVisitors = max(0, $todayVisitorCount - $returningCount);

        // ── Queries 5-8: Breakdowns (each is a single GROUP BY) ──
        $referrerTypeLabels = [
            'direct'   => '🏠 Trực tiếp',
            'search'   => '🔍 Tìm kiếm',
            'social'   => '📱 Mạng xã hội',
            'external' => '🔗 Liên kết ngoài',
        ];
        $referrers = $this->fetchBreakdown(
            $query, 'referrer_type', null, 20,
            fn ($r) => ['name' => $referrerTypeLabels[$r->col1] ?? $r->col1, 'count' => (int) $r->count]
        );

        $browsers = $this->fetchBreakdown(
            $query, 'browser', null, 10,
            fn ($r) => ['name' => $r->col1, 'count' => (int) $r->count]
        );

        $operatingSystems = $this->fetchBreakdown(
            $query, 'os', null, 10,
            fn ($r) => ['name' => $r->col1, 'count' => (int) $r->count]
        );

        $countries = $this->fetchBreakdown(
            $query, 'country_code', null, 20,
            fn ($r) => ['code' => $r->col1, 'count' => (int) $r->count]
        );

        // ── Query 9: Hourly distribution ──
        $hourlyRaw = (clone $query)
            ->where('is_bot', false)
            ->selectRaw('HOUR(visited_at) as hour')
            ->selectRaw('COUNT(*) as count')
            ->groupBy(DB::raw('HOUR(visited_at)'))
            ->pluck('count', 'hour')
            ->toArray();

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = (int) ($hourlyRaw[$h] ?? 0);
        }

        return [
            'total_views'            => (int) $core->total_views,
            'unique_visitors'        => (int) $core->unique_visitors,
            'new_visitors'           => $newVisitors,
            'returning_visitors'     => $returningCount,
            'desktop_views'          => (int) $core->desktop_views,
            'mobile_views'           => (int) $core->mobile_views,
            'tablet_views'           => (int) $core->tablet_views,
            'bot_views'              => (int) $core->bot_views,
            'bounce_rate'            => round((float) $sessionStats->bounce_rate, 2),
            'avg_pages_per_session'  => round((float) $sessionStats->avg_pages, 2),
            'referrer_breakdown'     => $referrers ?: null,
            'browser_breakdown'      => $browsers ?: null,
            'os_breakdown'           => $operatingSystems ?: null,
            'country_breakdown'      => $countries ?: null,
            'hourly_views'           => $hourly,
        ];
    }

    /**
     * Calculate session-level stats using a SQL derived table (subquery).
     *
     * Previous approach: ->get() loaded all sessions into PHP memory, then
     * filtered/counted in PHP. With 10K sessions this was ~10K rows.
     *
     * New approach: pure SQL — MySQL groups and aggregates in one pass.
     *
     * SQL equivalent:
     *   SELECT COUNT(*) AS total_sessions,
     *          SUM(CASE WHEN page_count = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 AS bounce_rate,
     *          SUM(page_count) / COUNT(*) AS avg_pages
     *   FROM (
     *     SELECT session_hash, COUNT(*) AS page_count
     *     FROM page_visits WHERE ... AND is_bot = 0
     *     GROUP BY session_hash
     *   ) sub
     *
     * @return object{total_sessions: int, bounce_rate: float, avg_pages: float}
     */
    private function calculateSessionStats($baseQuery): object
    {
        $humanQuery = (clone $baseQuery)->where('is_bot', false);

        // Build the inner subquery SQL
        $subQuery = $humanQuery
            ->select('session_hash')
            ->selectRaw('COUNT(*) as page_count')
            ->groupBy('session_hash');

        return DB::query()
            ->fromSub($subQuery, 'sessions')
            ->selectRaw('COUNT(*) as total_sessions')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN SUM(CASE WHEN page_count = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 ELSE 0 END as bounce_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN SUM(page_count) / COUNT(*) ELSE 0 END as avg_pages')
            ->first() ?? (object) ['total_sessions' => 0, 'bounce_rate' => 0, 'avg_pages' => 0];
    }

    /**
     * DRY helper: Fetch a GROUP BY breakdown from page_visits.
     *
     * @template T
     * @param  \Illuminate\Database\Eloquent\Builder  $baseQuery
     * @param  string  $col1  Primary GROUP BY column (e.g. 'browser')
     * @param  string|null  $col2  Optional secondary GROUP BY column (e.g. 'referrer_type')
     * @param  int  $limit
     * @param  callable  $mapper  Transform each row to output array
     * @return array<int, T>
     */
    private function fetchBreakdown($baseQuery, string $col1, ?string $col2, int $limit, callable $mapper): array
    {
        $query = (clone $baseQuery)
            ->where('is_bot', false)
            ->whereNotNull($col1)
            ->selectRaw("$col1 as col1")
            ->selectRaw('COUNT(*) as count');

        if ($col2 !== null) {
            $query->selectRaw("$col2 as col2");
            $query->groupBy($col1, $col2);
        } else {
            $query->groupBy($col1);
        }

        return $query
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map($mapper)
            ->toArray();
    }

    // ═══════════════════════════════════════════════════════════════
    // Upsert + Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Upsert a daily_analytics row.
     *
     * Handles the MySQL NULL uniqueness issue by using manual query
     * for site-wide/page-type-level rows where page_type or page_id is null.
     */
    private function upsertDailyAnalytic(string $date, ?string $pageType, ?int $pageId, ?int $apiDomainId, array $stats): void
    {
        // Build the WHERE condition that handles NULL comparison correctly
        $query = DailyAnalytic::query()->where('date', $date);

        if ($pageType === null) {
            $query->whereNull('page_type');
        } else {
            $query->where('page_type', $pageType);
        }

        if ($pageId === null) {
            $query->whereNull('page_id');
        } else {
            $query->where('page_id', $pageId);
        }

        if ($apiDomainId === null) {
            $query->whereNull('api_domain_id');
        } else {
            $query->where('api_domain_id', $apiDomainId);
        }

        $attributes = array_merge($stats, [
            'date'            => $date,
            'page_type'       => $pageType,
            'page_id'         => $pageId,
            'api_domain_id'   => $apiDomainId,
        ]);

        $existing = $query->first();

        if ($existing) {
            $existing->update($attributes);
        } else {
            DailyAnalytic::create($attributes);
        }
    }

    /**
     * Get day boundaries for index-friendly date queries.
     *
     * CRITICAL: Using whereDate('visited_at', $date) generates
     * DATE(visited_at) = $date which wraps the column in a function
     * and PREVENTS MySQL from using the idx_visits_time index.
     *
     * Using whereBetween('visited_at', [$start, $end]) allows MySQL
     * to perform an efficient index range scan.
     *
     * @return array{0: string, 1: string}
     */
    private function dayBounds(string $date): array
    {
        return [
            $date . ' 00:00:00',
            $date . ' 23:59:59',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Cleanup
    // ═══════════════════════════════════════════════════════════════

    /**
     * Delete page_visits older than N days.
     *
     * @return int Number of rows deleted
     */
    public function cleanupOldVisits(?int $days = null): int
    {
        $days = $days ?? config('analytics.retention.raw_days', 30);
        $cutoff = now()->subDays($days)->startOfDay();

        // Delete in chunks to avoid long-running transactions
        $totalDeleted = 0;
        do {
            $deleted = PageVisit::query()
                ->where('visited_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted > 0);

        return $totalDeleted;
    }

    /**
     * Delete daily_analytics older than N days.
     *
     * daily_analytics is much smaller than page_visits (~10 rows/day),
     * but should still be cleaned up to prevent unbounded growth.
     *
     * Default: 180 days (6 months) — configurable via analytics.retention.aggregated_days
     *
     * @return int Number of rows deleted
     */
    public function cleanupOldAggregates(?int $days = null): int
    {
        $days = $days ?? config('analytics.retention.aggregated_days', 180);
        $cutoff = now()->subDays($days)->toDateString();

        return DailyAnalytic::query()
            ->where('date', '<', $cutoff)
            ->delete();
    }
}

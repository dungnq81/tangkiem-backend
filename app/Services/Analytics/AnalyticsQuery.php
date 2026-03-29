<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\DailyAnalytic;
use App\Models\PageVisit;
use App\Services\Analytics\Collector\VisitorParser;
use App\Services\Analytics\Data\OverviewStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AnalyticsQuery — Unified query service for the analytics dashboard.
 *
 * Returns typed DTOs (OverviewStats) and pre-formatted collections.
 * All public methods accept an optional $siteId for per-site filtering.
 * $siteId = null → global (all sites), integer → specific FE site.
 *
 * Performance:
 * - Dashboard page load: ~11 queries (reduced from ~14)
 * - All page_visits queries use index-friendly date comparisons
 * - Breakdown columns fetched in single query + merged in PHP
 */
class AnalyticsQuery
{
    /** GA marker value in page_type column. */
    private const GA_MARKER = '_ga';

    // ═══════════════════════════════════════════════════════════════
    // Overview Stats
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get overview stats for a date range.
     */
    public function getOverview(string $from, string $to, ?int $siteId = null): OverviewStats
    {
        return $this->fetchOverview($from, $to, siteId: $siteId, siteWide: true);
    }

    /**
     * Get overview stats for the previous period (for trend comparison).
     */
    public function getPreviousPeriodOverview(string $from, string $to, ?int $siteId = null): OverviewStats
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $prevFrom = Carbon::parse($from)->subDays($days)->toDateString();
        $prevTo = Carbon::parse($from)->subDay()->toDateString();

        return $this->getOverview($prevFrom, $prevTo, $siteId);
    }

    /**
     * Get GA-sourced overview stats for a date range.
     */
    public function getGaOverview(string $from, string $to): OverviewStats
    {
        return $this->fetchOverview($from, $to, gaScope: true);
    }

    // ═══════════════════════════════════════════════════════════════
    // Time Series
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get daily views/visitors for charting.
     *
     * @return Collection<int, array{date: string, label: string, views: int, visitors: int}>
     */
    public function getTrafficTimeSeries(string $from, string $to, ?int $siteId = null): Collection
    {
        return $this->fetchTimeSeries($from, $to, siteId: $siteId, siteWide: true);
    }

    /**
     * Get GA-sourced daily views for charting.
     *
     * @return Collection<int, array{date: string, label: string, views: int, visitors: int}>
     */
    public function getGaTrafficTimeSeries(string $from, string $to): Collection
    {
        return $this->fetchTimeSeries($from, $to, gaScope: true);
    }

    /**
     * Get GA-sourced device breakdown.
     *
     * @return array{desktop: int, mobile: int, tablet: int}
     */
    public function getGaDeviceBreakdown(string $from, string $to): array
    {
        $stats = DailyAnalytic::query()
            ->where('page_type', self::GA_MARKER)
            ->whereNull('page_id')
            ->inDateRange($from, $to)
            ->selectRaw('COALESCE(SUM(desktop_views), 0) as desktop')
            ->selectRaw('COALESCE(SUM(mobile_views), 0) as mobile')
            ->selectRaw('COALESCE(SUM(tablet_views), 0) as tablet')
            ->first();

        return [
            'desktop' => (int) $stats->desktop,
            'mobile'  => (int) $stats->mobile,
            'tablet'  => (int) $stats->tablet,
        ];
    }

    /**
     * Get GA-sourced breakdown columns.
     *
     * @param  string[]  $columns
     * @return array<string, Collection<int, array{name: string, count: int}>>
     */
    public function getGaAllBreakdowns(string $from, string $to, array $columns, int $limit = 10): array
    {
        $query = DailyAnalytic::query()
            ->where('page_type', self::GA_MARKER)
            ->whereNull('page_id')
            ->inDateRange($from, $to);

        $query->where(function ($q) use ($columns) {
            foreach ($columns as $col) {
                $q->orWhereNotNull($col);
            }
        });

        $rows = $query->select($columns)->get();

        $results = [];
        foreach ($columns as $col) {
            $colData = $rows->pluck($col)->filter();
            $results[$col] = $this->mergeBreakdowns($colData, $limit);
        }

        return $results;
    }

    /**
     * Get GA-sourced hourly distribution.
     *
     * @return array<int, int>
     */
    public function getGaHourlyDistribution(string $from, string $to): array
    {
        $rows = DailyAnalytic::query()
            ->where('page_type', self::GA_MARKER)
            ->whereNull('page_id')
            ->inDateRange($from, $to)
            ->whereNotNull('hourly_views')
            ->pluck('hourly_views');

        $hourly = array_fill(0, 24, 0);
        foreach ($rows as $dailyHours) {
            if (!is_array($dailyHours)) {
                continue;
            }
            foreach ($dailyHours as $h => $count) {
                $hourly[$h] = ($hourly[$h] ?? 0) + (int) $count;
            }
        }

        return $hourly;
    }

    // ═══════════════════════════════════════════════════════════════
    // Breakdowns
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get device breakdown for the period.
     *
     * @return array{desktop: int, mobile: int, tablet: int}
     */
    public function getDeviceBreakdown(string $from, string $to, ?int $siteId = null): array
    {
        $stats = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->inDateRange($from, $to)
            ->selectRaw('COALESCE(SUM(desktop_views), 0) as desktop')
            ->selectRaw('COALESCE(SUM(mobile_views), 0) as mobile')
            ->selectRaw('COALESCE(SUM(tablet_views), 0) as tablet')
            ->first();

        return [
            'desktop' => (int) $stats->desktop,
            'mobile'  => (int) $stats->mobile,
            'tablet'  => (int) $stats->tablet,
        ];
    }

    /**
     * Fetch multiple breakdown columns in a single query.
     *
     * Instead of 4 separate queries (referrer, browser, os, country),
     * fetches all requested JSON columns in one SELECT and merges in PHP.
     *
     * @param  string[]  $columns  e.g. ['referrer_breakdown', 'browser_breakdown', ...]
     * @param  int  $limit  Max items per breakdown type
     * @return array<string, Collection<int, array{name: string, count: int}>>
     */
    public function getAllBreakdowns(string $from, string $to, array $columns, int $limit = 10, ?int $siteId = null): array
    {
        // Single query: select only the columns we need
        $query = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->inDateRange($from, $to);

        // Only fetch rows that have at least one non-null breakdown column
        $query->where(function ($q) use ($columns) {
            foreach ($columns as $col) {
                $q->orWhereNotNull($col);
            }
        });

        $rows = $query->select($columns)->get();

        // Merge each column's data separately
        $results = [];
        foreach ($columns as $col) {
            $colData = $rows->pluck($col)->filter();
            $results[$col] = $this->mergeBreakdowns($colData, $limit);
        }

        return $results;
    }

    /**
     * Aggregate a single JSON breakdown column across the period.
     *
     * @param  string  $column  e.g. 'referrer_breakdown', 'browser_breakdown'
     * @return Collection<int, array{name: string, count: int}>
     */
    public function getAggregatedBreakdown(string $from, string $to, string $column, int $limit = 10, ?int $siteId = null): Collection
    {
        $rows = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->inDateRange($from, $to)
            ->whereNotNull($column)
            ->pluck($column);

        return $this->mergeBreakdowns($rows, $limit);
    }

    /**
     * Get hourly distribution across the period.
     *
     * @return array<int, int> 24-element array (index = hour, value = total views)
     */
    public function getHourlyDistribution(string $from, string $to, ?int $siteId = null): array
    {
        $rows = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->inDateRange($from, $to)
            ->whereNotNull('hourly_views')
            ->pluck('hourly_views');

        $hourly = array_fill(0, 24, 0);
        foreach ($rows as $dailyHours) {
            if (!is_array($dailyHours)) {
                continue;
            }
            foreach ($dailyHours as $h => $count) {
                $hourly[$h] = ($hourly[$h] ?? 0) + (int) $count;
            }
        }

        return $hourly;
    }

    // ═══════════════════════════════════════════════════════════════
    // Top Content + Page Types
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get top pages by views.
     *
     * @return Collection<int, array{page_type: string, page_id: int, total_views: int, unique_visitors: int}>
     */
    public function getTopContent(string $from, string $to, int $limit = 10, ?int $siteId = null): Collection
    {
        return DailyAnalytic::query()
            ->inDateRange($from, $to)
            ->siteAware($siteId)
            ->whereNotNull('page_type')
            ->whereNotNull('page_id')
            ->select('page_type', 'page_id')
            ->selectRaw('SUM(total_views) as total_views')
            ->selectRaw('SUM(unique_visitors) as unique_visitors')
            ->groupBy('page_type', 'page_id')
            ->orderByDesc('total_views')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'page_type'       => $row->page_type,
                'page_id'         => (int) $row->page_id,
                'total_views'     => (int) $row->total_views,
                'unique_visitors' => (int) $row->unique_visitors,
            ]);
    }

    /**
     * Get per-page-type stats for the period.
     *
     * @return Collection<int, array{page_type: string, total_views: int, unique_visitors: int}>
     */
    public function getPageTypeStats(string $from, string $to, ?int $siteId = null): Collection
    {
        return DailyAnalytic::query()
            ->inDateRange($from, $to)
            ->siteAware($siteId)
            ->whereNotNull('page_type')
            ->where('page_type', '!=', self::GA_MARKER)
            ->whereNull('page_id')
            ->select('page_type')
            ->selectRaw('SUM(total_views) as total_views')
            ->selectRaw('SUM(unique_visitors) as unique_visitors')
            ->groupBy('page_type')
            ->orderByDesc('total_views')
            ->get()
            ->map(fn ($row) => [
                'page_type'       => $row->page_type,
                'total_views'     => (int) $row->total_views,
                'unique_visitors' => (int) $row->unique_visitors,
            ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Real-time (from page_visits — last N minutes)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Count active visitors in the last N minutes.
     *
     * Uses direct comparison (not whereDate) for index-friendly query.
     */
    public function getActiveVisitors(int $minutes = 30, ?int $siteId = null): int
    {
        return PageVisit::query()
            ->where('visited_at', '>=', now()->subMinutes($minutes))
            ->where('is_bot', false)
            ->siteAware($siteId)
            ->distinct('session_hash')
            ->count('session_hash');
    }

    /**
     * Count today's views (from aggregated or raw data).
     *
     * Uses index-friendly whereBetween for raw page_visits fallback.
     */
    public function getTodayViews(?int $siteId = null): int
    {
        $aggregated = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->where('date', now()->toDateString())
            ->sum('total_views');

        if ($aggregated > 0) {
            return (int) $aggregated;
        }

        // Fallback: index-friendly date range (not whereDate)
        $today = now()->toDateString();

        return PageVisit::query()
            ->whereBetween('visited_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
            ->where('is_bot', false)
            ->forSiteExact($siteId)
            ->count();
    }

    // ═══════════════════════════════════════════════════════════════
    // IP Monitoring (from page_visits)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get top IPs by visit count for the given date range.
     *
     * @return Collection<int, array{ip_address: string, total_views: int, is_bot: bool, latest_visit: string, browser: string, os: string, country_code: ?string}>
     */
    public function getTopIps(string $from, string $to, int $limit = 100, ?int $siteId = null): Collection
    {
        $parser = app(VisitorParser::class);

        return PageVisit::query()
            ->whereBetween('visited_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotNull('ip_address')
            ->when($siteId !== null, fn ($q) => $q->where('api_domain_id', $siteId))
            ->select('ip_address')
            ->selectRaw('COUNT(*) as total_views')
            ->selectRaw('MAX(is_bot) as is_bot')
            ->selectRaw('MAX(visited_at) as latest_visit')
            ->selectRaw('ANY_VALUE(user_agent) as user_agent')
            ->selectRaw('ANY_VALUE(browser) as browser')
            ->selectRaw('ANY_VALUE(os) as os')
            ->selectRaw('ANY_VALUE(country_code) as country_code')
            ->groupBy('ip_address')
            ->orderByDesc('total_views')
            ->limit($limit)
            ->get()
            ->map(function ($row) use ($parser) {
                // Re-parse from raw UA if available (most accurate)
                // Falls back to stored browser/os for old data without user_agent
                $ua = $row->user_agent;
                if ($ua) {
                    $parsed = $parser->parseUserAgent($ua);
                    $browser = $parsed['browser'];
                    $os = $parsed['os'];
                } else {
                    $browser = $row->browser ?? 'Unknown';
                    $os = $row->os ?? 'Unknown';
                }

                return [
                    'ip_address'   => $row->ip_address,
                    'total_views'  => (int) $row->total_views,
                    'is_bot'       => (bool) $row->is_bot,
                    'latest_visit' => $row->latest_visit,
                    'browser'      => $browser,
                    'os'           => $os,
                    'country_code' => $row->country_code,
                    'user_agent'   => $ua ? mb_substr($ua, 0, 80) . (mb_strlen($ua) > 80 ? '…' : '') : null,
                ];
            });
    }

    /**
     * Get bot statistics for a date range from daily_analytics.
     *
     * @return array{total_bot_views: int, bot_percentage: float}
     */
    public function getBotStats(string $from, string $to, ?int $siteId = null): array
    {
        $stats = DailyAnalytic::query()
            ->siteWide()
            ->forSiteExact($siteId)
            ->inDateRange($from, $to)
            ->selectRaw('COALESCE(SUM(total_views), 0) as human_views')
            ->selectRaw('COALESCE(SUM(bot_views), 0) as bot_views')
            ->first();

        $human = (int) ($stats->human_views ?? 0);
        $bot = (int) ($stats->bot_views ?? 0);
        $total = $human + $bot;

        return [
            'total_bot_views' => $bot,
            'bot_percentage'  => $total > 0 ? round(($bot / $total) * 100, 1) : 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Private: Shared Query Builders (DRY)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fetch overview stats with configurable scope.
     */
    private function fetchOverview(
        string $from,
        string $to,
        ?int $siteId = null,
        bool $siteWide = false,
        bool $gaScope = false,
    ): OverviewStats {
        $query = DailyAnalytic::query()->inDateRange($from, $to);

        if ($gaScope) {
            $query->where('page_type', self::GA_MARKER)->whereNull('page_id');
        } elseif ($siteWide) {
            $query->siteWide();
        }

        // Use forSiteExact: NULL = only global row, integer = only that site's row
        // This prevents double-counting (global + per-site)
        $query->forSiteExact($siteId);

        $stats = $query
            ->selectRaw('COALESCE(SUM(total_views), 0) as total_views')
            ->selectRaw('COALESCE(SUM(unique_visitors), 0) as unique_visitors')
            ->selectRaw('COALESCE(SUM(new_visitors), 0) as new_visitors')
            ->selectRaw('COALESCE(SUM(bot_views), 0) as bot_views')
            ->selectRaw('COALESCE(AVG(bounce_rate), 0) as bounce_rate')
            ->selectRaw('COALESCE(AVG(avg_pages_per_session), 0) as avg_pages')
            ->first();

        return OverviewStats::fromAggregate($stats);
    }

    /**
     * Fetch time series data with configurable scope.
     *
     * @return Collection<int, array{date: string, label: string, views: int, visitors: int}>
     */
    private function fetchTimeSeries(
        string $from,
        string $to,
        ?int $siteId = null,
        bool $siteWide = false,
        bool $gaScope = false,
    ): Collection {
        $query = DailyAnalytic::query()->inDateRange($from, $to);

        if ($gaScope) {
            $query->where('page_type', self::GA_MARKER)->whereNull('page_id');
        } elseif ($siteWide) {
            $query->siteWide();
        }

        $query->forSiteExact($siteId);

        $data = $query
            ->select('date', 'total_views', 'unique_visitors')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'))
            ->all();

        // Fill missing dates with zero values
        $result = collect();
        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($current->lte($end)) {
            $key = $current->format('Y-m-d');
            $row = $data[$key] ?? null;

            $result->push([
                'date'     => $key,
                'label'    => $current->format('d/m'),
                'views'    => $row ? (int) $row->total_views : 0,
                'visitors' => $row ? (int) $row->unique_visitors : 0,
            ]);

            $current->addDay();
        }

        return $result;
    }

    /**
     * Merge JSON breakdown data from multiple daily rows.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    private function mergeBreakdowns(Collection $rows, int $limit): Collection
    {
        $merged = [];

        foreach ($rows as $breakdown) {
            if (!is_array($breakdown)) {
                continue;
            }
            foreach ($breakdown as $item) {
                $key = $item['name'] ?? $item['domain'] ?? $item['code'] ?? 'Khác';

                // Normalize legacy/garbage values to 'Khác'
                if ($key === null || in_array(strtolower(trim((string) $key)), [
                    'unknown', '', 'null', 'none', 'other', 'n/a', '-',
                ], true)) {
                    $key = 'Khác';
                }

                $merged[$key] = ($merged[$key] ?? 0) + (int) ($item['count'] ?? 0);
            }
        }

        arsort($merged);
        $merged = array_slice($merged, 0, $limit, true);

        return collect($merged)->map(fn (int $count, string $name) => [
            'name'  => $name,
            'count' => $count,
        ])->values();
    }
}

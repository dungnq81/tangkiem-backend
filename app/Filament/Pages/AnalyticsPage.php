<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ApiDomain;
use App\Services\Analytics\AnalyticsQuery;
use App\Services\Analytics\GoogleAnalytics\GaClient;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Filament Analytics Dashboard Page.
 *
 * Self-hosted analytics overview with:
 * - Site selector (All Sites / specific FE site)
 * - Real-time active visitors
 * - Overview stats (views, visitors, bounce rate)
 * - Traffic chart (daily views + visitors)
 * - Device breakdown (donut chart)
 * - Top referrers, browsers, OS
 * - Top content pages
 * - Hourly distribution
 */
class AnalyticsPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Analytics';

    protected static string | UnitEnum | null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Analytics';

    protected static ?string $slug = 'analytics';

    protected string $view = 'filament.pages.analytics';

    /**
     * Date range filter — persisted in URL query string.
     */
    #[Url(as: 'period')]
    public string $period = '7d';

    /**
     * Data source: 'self' (self-hosted), 'ga' (Google Analytics), 'compare' (side-by-side).
     */
    #[Url(as: 'source')]
    public string $source = 'self';

    /**
     * Site filter: null = all sites, integer = specific ApiDomain ID.
     * Persisted in URL so selection survives page reload.
     */
    #[Url(as: 'site')]
    public ?string $siteId = null;

    // ═══════════════════════════════════════════════════════════════
    // Computed Data
    // ═══════════════════════════════════════════════════════════════

    protected function getViewData(): array
    {
        $query = app(AnalyticsQuery::class);
        [$from, $to] = $this->getDateRange();
        $siteId = $this->getResolvedSiteId();

        // Overview
        $overview = $query->getOverview($from, $to, $siteId);
        $prevOverview = $query->getPreviousPeriodOverview($from, $to, $siteId);

        // Traffic chart
        $traffic = $query->getTrafficTimeSeries($from, $to, $siteId);

        // Device breakdown
        $devices = $query->getDeviceBreakdown($from, $to, $siteId);

        // Breakdowns — single query for all 4 columns (saves 3 DB hits vs individual calls)
        $breakdowns = $query->getAllBreakdowns($from, $to, [
            'referrer_breakdown',
            'browser_breakdown',
            'os_breakdown',
            'country_breakdown',
        ], 10, $siteId);

        $referrers = $breakdowns['referrer_breakdown'];
        $browsers = $breakdowns['browser_breakdown'];
        $oses = $breakdowns['os_breakdown'];
        $countries = $breakdowns['country_breakdown'];

        // Hourly distribution
        $hourly = $query->getHourlyDistribution($from, $to, $siteId);

        // Page types
        $pageTypes = $query->getPageTypeStats($from, $to, $siteId);

        // Real-time
        $activeVisitors = $query->getActiveVisitors(30, $siteId);
        $todayViews = $query->getTodayViews($siteId);

        // Bot monitoring
        $botStats = $query->getBotStats($from, $to, $siteId);

        // Top IPs (from raw page_visits)
        $topIps = $query->getTopIps($from, $to, 100, $siteId);

        // GA status
        $gaEnabled = app(GaClient::class)->isEnabled();

        // GA data (if source is 'ga' or 'compare')
        $gaOverview = null;
        $gaTraffic = null;
        $gaDevices = null;
        $gaReferrers = null;
        $gaBrowsers = null;
        $gaOses = null;
        $gaCountries = null;
        $gaHourly = null;

        if ($gaEnabled && in_array($this->source, ['ga', 'compare'])) {
            $gaOverview = $query->getGaOverview($from, $to);
            $gaTraffic = $query->getGaTrafficTimeSeries($from, $to);
            $gaDevices = $query->getGaDeviceBreakdown($from, $to);

            $gaBreakdowns = $query->getGaAllBreakdowns($from, $to, [
                'referrer_breakdown',
                'browser_breakdown',
                'os_breakdown',
                'country_breakdown',
            ], 10);

            $gaReferrers = $gaBreakdowns['referrer_breakdown'];
            $gaBrowsers = $gaBreakdowns['browser_breakdown'];
            $gaOses = $gaBreakdowns['os_breakdown'];
            $gaCountries = $gaBreakdowns['country_breakdown'];
            $gaHourly = $query->getGaHourlyDistribution($from, $to);
        }

        // Available sites for selector
        $sites = ApiDomain::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'domain']);

        return [
            'overview'       => $overview,
            'prevOverview'   => $prevOverview,
            'traffic'        => $traffic,
            'devices'        => $devices,
            'referrers'      => $referrers,
            'browsers'       => $browsers,
            'oses'           => $oses,
            'countries'      => $countries,
            'hourly'         => $hourly,
            'pageTypes'      => $pageTypes,
            'activeVisitors' => $activeVisitors,
            'todayViews'     => $todayViews,
            'botStats'       => $botStats,
            'topIps'         => $topIps,
            'period'         => $this->period,
            'source'         => $this->source,
            'siteId'         => $this->siteId,
            'sites'          => $sites,
            'dateFrom'       => $from,
            'dateTo'         => $to,
            'gaEnabled'      => $gaEnabled,
            'gaOverview'     => $gaOverview,
            'gaTraffic'      => $gaTraffic,
            'gaDevices'      => $gaDevices,
            'gaReferrers'    => $gaReferrers,
            'gaBrowsers'     => $gaBrowsers,
            'gaOses'         => $gaOses,
            'gaCountries'    => $gaCountries,
            'gaHourly'       => $gaHourly,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Date Range
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array{0: string, 1: string}
     */
    private function getDateRange(): array
    {
        $to = now()->toDateString();

        $from = match ($this->period) {
            '1d'  => now()->toDateString(),
            '7d'  => now()->subDays(6)->toDateString(),
            '14d' => now()->subDays(13)->toDateString(),
            '30d' => now()->subDays(29)->toDateString(),
            '90d' => now()->subDays(89)->toDateString(),
            default => now()->subDays(6)->toDateString(),
        };

        return [$from, $to];
    }

    // ═══════════════════════════════════════════════════════════════
    // Livewire Actions
    // ═══════════════════════════════════════════════════════════════

    private const VALID_PERIODS = ['1d', '7d', '14d', '30d', '90d'];

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, self::VALID_PERIODS, true) ? $period : '7d';
    }

    private const VALID_SOURCES = ['self', 'ga', 'compare'];

    public function setSource(string $source): void
    {
        $this->source = in_array($source, self::VALID_SOURCES, true) ? $source : 'self';
    }

    public function setSiteId(?string $siteId): void
    {
        $this->siteId = $siteId === '' ? null : $siteId;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers (used in view)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve siteId from Livewire string property to nullable int.
     */
    private function getResolvedSiteId(): ?int
    {
        return $this->siteId !== null && $this->siteId !== ''
            ? (int) $this->siteId
            : null;
    }

    /**
     * Calculate percentage change between current and previous period.
     */
    public static function percentChange(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Format large numbers.
     */
    public static function formatNumber(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 => number_format($number / 1_000_000, 1) . 'M',
            $number >= 10_000   => number_format($number / 1_000, 1) . 'K',
            default             => number_format($number),
        };
    }
}

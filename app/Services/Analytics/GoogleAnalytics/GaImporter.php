<?php

declare(strict_types=1);

namespace App\Services\Analytics\GoogleAnalytics;

use App\Models\DailyAnalytic;
use App\Services\Analytics\Data\ImportResult;
use Google\ApiCore\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GaImporter — GA4 data import orchestrator.
 *
 * Coordinates GaClient (API queries) with data transformation and persistence.
 * Returns immutable ImportResult DTOs instead of raw arrays.
 *
 * Separation of concerns:
 * - GaClient: knows how to talk to GA4 API
 * - GaImporter: knows how to map GA4 data → daily_analytics schema
 */
class GaImporter
{
    /** Rate limit delay between API calls (microseconds). */
    private const API_DELAY_US = 200_000; // 200ms

    public function __construct(
        private readonly GaClient $client,
    ) {}

    /**
     * Import GA4 data for a specific date into daily_analytics.
     */
    public function importForDate(string $date): ImportResult
    {
        if (!$this->client->isEnabled()) {
            return ImportResult::failure('GA4 not configured');
        }

        try {
            // Fetch all reports from GA4
            $core = $this->client->fetchCoreMetrics($date);
            $devices = $this->client->fetchDimensionBreakdown($date, 'deviceCategory', 'screenPageViews');
            $referrers = $this->client->fetchDimensionBreakdown($date, 'sessionSource', 'sessions', 20);
            $browsers = $this->client->fetchDimensionBreakdown($date, 'browser', 'screenPageViews', 10);
            $oses = $this->client->fetchDimensionBreakdown($date, 'operatingSystem', 'screenPageViews', 10);
            $countries = $this->client->fetchDimensionBreakdown($date, 'country', 'screenPageViews', 20);
            $hourly = $this->client->fetchHourlyDistribution($date);

            // Transform GA4 data → daily_analytics schema
            $stats = $this->buildStats($core, $devices, $referrers, $browsers, $oses, $countries, $hourly);

            // Persist
            $this->upsertGaStats($date, $stats);

            return ImportResult::success($stats);
        } catch (ApiException $e) {
            Log::error("GA4 import failed for {$date}: " . $e->getMessage());

            return ImportResult::failure($e->getMessage());
        } catch (\Throwable $e) {
            Log::error("GA4 import unexpected error for {$date}: " . $e->getMessage());

            return ImportResult::failure($e->getMessage());
        }
    }

    /**
     * Import GA4 data for a date range.
     *
     * @return array{total_imported: int, errors: array<string>}
     */
    public function importDateRange(string $from, string $to): array
    {
        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        $totalImported = 0;
        $errors = [];

        while ($current->lte($end)) {
            $date = $current->toDateString();
            $result = $this->importForDate($date);

            if ($result->imported) {
                $totalImported++;
            } elseif ($result->error) {
                $errors[] = "{$date}: {$result->error}";
            }

            $current->addDay();
            usleep(self::API_DELAY_US);
        }

        return [
            'total_imported' => $totalImported,
            'errors'         => $errors,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Data Transformation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build stats array compatible with daily_analytics schema.
     *
     * @return array<string, mixed>
     */
    private function buildStats(
        array $core,
        array $devices,
        array $referrers,
        array $browsers,
        array $oses,
        array $countries,
        array $hourly,
    ): array {
        // Parse device breakdown
        $desktopViews = 0;
        $mobileViews = 0;
        $tabletViews = 0;

        foreach ($devices as $device) {
            $name = strtolower($device['name']);
            match (true) {
                str_contains($name, 'desktop') => $desktopViews = $device['count'],
                str_contains($name, 'mobile')  => $mobileViews = $device['count'],
                str_contains($name, 'tablet')  => $tabletViews = $device['count'],
                default                         => null,
            };
        }

        return [
            'total_views'           => $core['pageviews'],
            'unique_visitors'       => $core['users'],
            'new_visitors'          => $core['new_users'],
            'returning_visitors'    => max(0, $core['users'] - $core['new_users']),
            'desktop_views'         => $desktopViews,
            'mobile_views'          => $mobileViews,
            'tablet_views'          => $tabletViews,
            'bot_views'             => 0, // GA4 filters bots
            'bounce_rate'           => $core['bounce_rate'],
            'avg_pages_per_session' => $core['pages_per_session'],
            'referrer_breakdown'    => $this->formatReferrers($referrers) ?: null,
            'browser_breakdown'     => $this->formatSimpleBreakdown($browsers) ?: null,
            'os_breakdown'          => $this->formatSimpleBreakdown($oses) ?: null,
            'country_breakdown'     => $this->formatCountries($countries) ?: null,
            'hourly_views'          => $hourly,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Formatters
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array<int, array{domain: string, type: string, count: int}>
     */
    private function formatReferrers(array $referrers): array
    {
        return array_map(fn (array $r) => [
            'domain' => $r['name'],
            'type'   => $this->classifyGaSource($r['name']),
            'count'  => $r['count'],
        ], $referrers);
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    private function formatSimpleBreakdown(array $items): array
    {
        return array_map(fn (array $item) => [
            'name'  => $item['name'],
            'count' => $item['count'],
        ], $items);
    }

    /**
     * @return array<int, array{code: string, count: int}>
     */
    private function formatCountries(array $countries): array
    {
        return array_map(fn (array $c) => [
            'code'  => $c['name'], // GA4 returns country name
            'count' => $c['count'],
        ], $countries);
    }

    /**
     * Classify a GA4 source into referrer type.
     */
    private function classifyGaSource(string $source): string
    {
        $source = strtolower($source);

        $searchEngines = ['google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'coc coc'];
        foreach ($searchEngines as $engine) {
            if (str_contains($source, $engine)) {
                return 'search';
            }
        }

        $socialNetworks = ['facebook', 'twitter', 'instagram', 'tiktok', 'youtube', 'reddit', 'linkedin', 'pinterest', 'telegram', 'zalo'];
        foreach ($socialNetworks as $network) {
            if (str_contains($source, $network)) {
                return 'social';
            }
        }

        if ($source === '(direct)' || $source === '(none)') {
            return 'direct';
        }

        return 'external';
    }

    // ═══════════════════════════════════════════════════════════════
    // Persistence
    // ═══════════════════════════════════════════════════════════════

    /**
     * Upsert GA data into daily_analytics with page_type = '_ga'.
     */
    private function upsertGaStats(string $date, array $stats): void
    {
        $existing = DailyAnalytic::query()
            ->where('date', $date)
            ->where('page_type', '_ga')
            ->whereNull('page_id')
            ->first();

        $attributes = array_merge($stats, [
            'date'      => $date,
            'page_type' => '_ga',
            'page_id'   => null,
        ]);

        if ($existing) {
            $existing->update($attributes);
        } else {
            DailyAnalytic::create($attributes);
        }
    }
}

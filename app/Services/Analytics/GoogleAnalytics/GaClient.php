<?php

declare(strict_types=1);

namespace App\Services\Analytics\GoogleAnalytics;

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;

/**
 * GaClient — Low-level GA4 Data API wrapper.
 *
 * Single responsibility: execute report queries against GA4 API.
 * Does NOT know about daily_analytics table or import logic.
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1
 */
class GaClient
{
    private ?BetaAnalyticsDataClient $client = null;

    private string $propertyId;

    private string $credentialsPath;

    public function __construct()
    {
        $this->propertyId = (string) config('analytics.google_analytics.property_id', '');
        $this->credentialsPath = (string) config('analytics.google_analytics.credentials', '');
    }

    /**
     * Check if GA4 integration is properly configured.
     */
    public function isEnabled(): bool
    {
        return config('analytics.google_analytics.enabled', false)
            && !empty($this->propertyId)
            && !empty($this->credentialsPath)
            && file_exists($this->credentialsPath);
    }

    /**
     * Get current configuration for diagnostics.
     *
     * @return array{enabled: bool, property_id: string, credentials: string}
     */
    public function getConfig(): array
    {
        return [
            'enabled'     => (bool) config('analytics.google_analytics.enabled', false),
            'property_id' => $this->propertyId,
            'credentials' => $this->credentialsPath,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Report Queries
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fetch core traffic metrics for a date.
     *
     * @return array{pageviews: int, users: int, new_users: int, sessions: int, bounce_rate: float, pages_per_session: float}
     */
    public function fetchCoreMetrics(string $date): array
    {
        $request = (new RunReportRequest())
            ->setProperty("properties/{$this->propertyId}")
            ->setDateRanges([
                (new DateRange())->setStartDate($date)->setEndDate($date),
            ])
            ->setMetrics([
                (new Metric())->setName('screenPageViews'),
                (new Metric())->setName('activeUsers'),
                (new Metric())->setName('newUsers'),
                (new Metric())->setName('sessions'),
                (new Metric())->setName('bounceRate'),
                (new Metric())->setName('screenPageViewsPerSession'),
            ]);

        $response = $this->getClient()->runReport($request);
        $row = $response->getRows();

        if ($row === null || count($row) === 0) {
            return [
                'pageviews'         => 0,
                'users'             => 0,
                'new_users'         => 0,
                'sessions'          => 0,
                'bounce_rate'       => 0,
                'pages_per_session' => 0,
            ];
        }

        $values = $row[0]->getMetricValues();

        return [
            'pageviews'         => (int) $values[0]->getValue(),
            'users'             => (int) $values[1]->getValue(),
            'new_users'         => (int) $values[2]->getValue(),
            'sessions'          => (int) $values[3]->getValue(),
            'bounce_rate'       => round((float) $values[4]->getValue() * 100, 2),
            'pages_per_session' => round((float) $values[5]->getValue(), 2),
        ];
    }

    /**
     * Fetch a dimension breakdown (browser, OS, country, device, etc.).
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function fetchDimensionBreakdown(
        string $date,
        string $dimension,
        string $metric = 'screenPageViews',
        int $limit = 10,
    ): array {
        $request = (new RunReportRequest())
            ->setProperty("properties/{$this->propertyId}")
            ->setDateRanges([
                (new DateRange())->setStartDate($date)->setEndDate($date),
            ])
            ->setDimensions([
                (new Dimension())->setName($dimension),
            ])
            ->setMetrics([
                (new Metric())->setName($metric),
            ])
            ->setLimit($limit);

        $response = $this->getClient()->runReport($request);

        $results = [];
        foreach ($response->getRows() as $row) {
            $name = $row->getDimensionValues()[0]->getValue();
            $count = (int) $row->getMetricValues()[0]->getValue();

            if ($name === '(not set)' || $count === 0) {
                continue;
            }

            $results[] = ['name' => $name, 'count' => $count];
        }

        return $results;
    }

    /**
     * Fetch hourly distribution for a date.
     *
     * @return array<int, int> 24-element array indexed by hour
     */
    public function fetchHourlyDistribution(string $date): array
    {
        $request = (new RunReportRequest())
            ->setProperty("properties/{$this->propertyId}")
            ->setDateRanges([
                (new DateRange())->setStartDate($date)->setEndDate($date),
            ])
            ->setDimensions([
                (new Dimension())->setName('hour'),
            ])
            ->setMetrics([
                (new Metric())->setName('screenPageViews'),
            ]);

        $response = $this->getClient()->runReport($request);

        $hourly = array_fill(0, 24, 0);
        foreach ($response->getRows() as $row) {
            $hour = (int) $row->getDimensionValues()[0]->getValue();
            $count = (int) $row->getMetricValues()[0]->getValue();
            $hourly[$hour] = $count;
        }

        return $hourly;
    }

    // ═══════════════════════════════════════════════════════════════
    // Client Factory
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get or create the GA4 API client (lazy singleton).
     */
    private function getClient(): BetaAnalyticsDataClient
    {
        if ($this->client === null) {
            $this->client = new BetaAnalyticsDataClient([
                'credentials' => $this->credentialsPath,
                'transport'   => 'rest', // No gRPC extension required
            ]);
        }

        return $this->client;
    }
}

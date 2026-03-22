<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collector;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Log;

/**
 * GeoIP Resolver — Country-level geolocation from IP address.
 *
 * Uses MaxMind GeoLite2-Country database (free, requires registration).
 * Downloads: https://dev.maxmind.com/geoip/geolite2-free-geolocation-data
 *
 * When disabled or database file missing, returns null (graceful degradation).
 * Performance: Reader is cached in constructor (single file open per request).
 */
class GeoIpResolver
{
    private readonly bool $enabled;

    private ?Reader $reader = null;

    public function __construct()
    {
        $this->enabled = (bool) config('analytics.geolocation.enabled', false);

        if ($this->enabled) {
            $dbPath = (string) config('analytics.geolocation.database', '');

            if ($dbPath !== '' && file_exists($dbPath)) {
                try {
                    $this->reader = new Reader($dbPath);
                } catch (\Throwable $e) {
                    Log::warning("GeoIP: Failed to open database: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Resolve ISO 3166-1 alpha-2 country code from IP address.
     *
     * Returns null if:
     * - Geolocation is disabled
     * - Database file not found
     * - IP is private/reserved (127.0.0.1, 192.168.x.x, etc.)
     * - IP not found in database
     *
     * @return string|null  e.g. "VN", "US", "JP"
     */
    public function resolve(string $ip): ?string
    {
        if ($this->reader === null) {
            return null;
        }

        try {
            $record = $this->reader->country($ip);

            return $record->country->isoCode;
        } catch (\GeoIp2\Exception\AddressNotFoundException) {
            // Private/reserved IPs (127.0.0.1, 192.168.x.x) — normal in dev
            return null;
        } catch (\Throwable $e) {
            // Don't let geolocation break the request
            Log::debug("GeoIP lookup failed for {$ip}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Check if geolocation is active (enabled + database loaded).
     */
    public function isActive(): bool
    {
        return $this->reader !== null;
    }
}

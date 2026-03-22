<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collector;

/**
 * IP Anonymizer — Privacy-first IP handling.
 *
 * Extracts IP anonymization and hashing from AnalyticsCollector
 * into a focused, single-responsibility service.
 *
 * - Strips last octet for IPv4, last 5 groups for IPv6
 * - Creates session hashes (IP + User-Agent → xxh3)
 * - Creates IP hashes with daily rotating salt
 *
 * Performance: All config values cached in constructor (no config() lookups in hot path).
 */
class IpAnonymizer
{
    private readonly bool $anonymizeIp;

    private readonly bool $dailySalt;

    private readonly string $appKey;

    public function __construct()
    {
        $this->anonymizeIp = (bool) config('analytics.privacy.anonymize_ip', true);
        $this->dailySalt = (bool) config('analytics.privacy.daily_salt', true);
        $this->appKey = (string) config('app.key', '');
    }

    /**
     * Create session hash from IP + User-Agent.
     * Same hash = same visitor within a day.
     */
    public function makeSessionHash(string $ip, string $userAgent): string
    {
        $input = $this->anonymize($ip) . '|' . $userAgent;

        return hash('xxh3', $input);
    }

    /**
     * Create IP hash with optional daily rotating salt.
     * Prevents long-term tracking while allowing daily dedup.
     */
    public function makeIpHash(string $ip): string
    {
        $anonymized = $this->anonymize($ip);

        if ($this->dailySalt) {
            $salt = now()->format('Y-m-d') . $this->appKey;

            return hash('xxh3', $anonymized . '|' . $salt);
        }

        return hash('xxh3', $anonymized);
    }

    /**
     * Anonymize an IP address for privacy.
     *
     * IPv4: 192.168.1.100 → 192.168.1.0
     * IPv6: 2001:db8:85a3::8a2e:370:7334 → 2001:db8:85a3::
     */
    public function anonymize(string $ip): string
    {
        if (!$this->anonymizeIp) {
            return $ip;
        }

        // IPv4: strip last octet
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }

        // IPv6: strip last 5 groups (80 bits)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $preserved = array_slice($parts, 0, 3);

            return implode(':', $preserved) . '::';
        }

        return $ip;
    }
}

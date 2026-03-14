<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\Data\ProxyConfig;

/**
 * Strategy interface for proxy providers.
 *
 * Implement this to add rotating proxy, smart proxy, or static proxy support.
 *
 * Usage example:
 *   $proxy = $provider->getProxy('example.com');
 *   // Use $proxy->toUrl() in cURL or Playwright
 *   // On failure: $provider->reportFailure($proxy);
 */
interface ProxyProviderInterface
{
    /**
     * Get a proxy for the given domain.
     *
     * @param  string|null  $domain  Target domain (for domain-specific proxy pools)
     * @return ProxyConfig|null  Returns null if no proxy should be used
     */
    public function getProxy(?string $domain = null): ?ProxyConfig;

    /**
     * Report a proxy failure for health tracking.
     */
    public function reportFailure(ProxyConfig $proxy, ?\Throwable $reason = null): void;

    /**
     * Report a successful request through a proxy.
     */
    public function reportSuccess(ProxyConfig $proxy): void;
}

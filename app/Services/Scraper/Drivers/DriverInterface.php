<?php

declare(strict_types=1);

namespace App\Services\Scraper\Drivers;

interface DriverInterface
{
    /**
     * Fetch HTML content from URL.
     *
     * @param  string  $url      URL to fetch
     * @param  array   $headers  Custom headers (or cookies)
     * @return string  Raw HTML content
     *
     * @throws \RuntimeException on failure
     */
    public function fetchHtml(string $url, array $headers = []): string;
}

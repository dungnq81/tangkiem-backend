<?php

declare(strict_types=1);

namespace App\Services\Scraper\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * URL resolution, pagination, and navigation helpers.
 *
 * Extracted from ScraperService to reduce file size.
 * Used by ScraperService and scraping strategy classes.
 */
trait ResolvesUrls
{
    /**
     * Resolve full URL for an item from its raw data.
     */
    public function resolveItemUrl(array $rawData, string $baseUrl): string
    {
        $url = $rawData['url'] ?? $rawData['href'] ?? '';

        if (empty($url)) {
            return $baseUrl;
        }

        return $this->resolveAbsoluteUrl($url, $baseUrl);
    }

    /**
     * Resolve a potentially relative URL to absolute.
     */
    public function resolveAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Resolve page URLs from pagination config (for query_param type).
     */
    public function resolvePages(string $baseUrl, ?array $pagination): array
    {
        if (! $pagination) {
            return [1 => $baseUrl];
        }

        $type = $pagination['type'] ?? 'single';

        if ($type === 'query_param') {
            $start = (int) ($pagination['start_page'] ?? 0);
            $end = (int) ($pagination['end_page'] ?? $start);
            $pattern = $pagination['url_pattern'] ?? $baseUrl;
            $firstPageIsBaseUrl = (bool) ($pagination['first_page_is_base_url'] ?? false);

            $pages = [];

            if ($start <= $end) {
                for ($i = $start; $i <= $end; $i++) {
                    $pages[$i] = ($firstPageIsBaseUrl && $i === $start)
                        ? $baseUrl
                        : str_replace('{page}', (string) $i, $pattern);
                }
            } else {
                for ($i = $start; $i >= $end; $i--) {
                    $pages[$i] = ($firstPageIsBaseUrl && $i === $start)
                        ? $baseUrl
                        : str_replace('{page}', (string) $i, $pattern);
                }
            }

            return $pages;
        }

        return [1 => $baseUrl];
    }

    /**
     * Find the next page URL using a CSS selector on the HTML.
     */
    public function findNextPageUrl(string $html, string $selector, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html);
            $nextLink = $crawler->filter($selector);

            if ($nextLink->count() === 0) {
                return null;
            }

            $href = $nextLink->first()->attr('href');
            if (! $href || $href === '#' || $href === 'javascript:void(0)') {
                return null;
            }

            // Skip disabled links (e.g. prev/next buttons on first/last chapter)
            if ($nextLink->first()->attr('disabled') !== null) {
                return null;
            }

            return $this->resolveAbsoluteUrl($href, $baseUrl);
        } catch (\Exception $e) {
            Log::warning('Failed to find next page link', [
                'selector' => $selector,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}

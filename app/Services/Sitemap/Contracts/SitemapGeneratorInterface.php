<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Contracts;

/**
 * Contract for sitemap generators.
 *
 * Each generator is responsible for building XML sitemap content
 * for a single entity type (stories, chapters, categories, etc.).
 *
 * To add a new entity type:
 * 1. Create a class implementing this interface (extend AbstractGenerator for convenience)
 * 2. Register it in config/sitemap.php under 'generators'
 */
interface SitemapGeneratorInterface
{
    /**
     * Unique identifier for this generator (e.g., 'stories', 'chapters').
     * Used in cache keys and sitemap filenames.
     */
    public function type(): string;

    /**
     * Whether this generator supports pagination (large datasets).
     * If true, totalCount() and build($page) are used for multi-page sitemaps.
     */
    public function supportsPagination(): bool;

    /**
     * Total number of URLs this generator will produce.
     * Used by the sitemap index to determine pagination.
     */
    public function totalCount(): int;

    /**
     * Check if there are any URLs to generate.
     */
    public function hasUrls(): bool;

    /**
     * Build the XML sitemap content for the given page.
     *
     * @param  string  $baseUrl  The frontend base URL (e.g., "https://tangkiem.xyz")
     * @param  int     $page     Page number (1-based). Only relevant for paginated generators.
     * @return string|null       XML content, or null if no URLs for this page.
     */
    public function build(string $baseUrl, int $page = 1): ?string;
}

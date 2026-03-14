<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Services\Sitemap\Contracts\SitemapGeneratorInterface;
use XMLWriter;

/**
 * Base class for sitemap generators.
 *
 * Provides shared XML building utilities. Subclasses only need to implement
 * the abstract methods to define their specific entity logic.
 */
abstract class AbstractGenerator implements SitemapGeneratorInterface
{
    /**
     * Get the URL pattern for this entity type from config.
     */
    protected function urlPattern(): string
    {
        return (string) config("sitemap.url_patterns.{$this->type()}", "/{slug}");
    }

    /**
     * Get the SEO priority for this entity type from config.
     */
    protected function priority(): string
    {
        return (string) config("sitemap.priorities.{$this->type()}", '0.5');
    }

    /**
     * Get the change frequency for this entity type from config.
     */
    protected function changefreq(): string
    {
        return (string) config("sitemap.changefreq.{$this->type()}", 'weekly');
    }

    /**
     * Get the max URLs per sitemap file from config.
     */
    protected function maxUrlsPerFile(): int
    {
        return (int) config('sitemap.max_urls_per_file', 45000);
    }

    // ═══════════════════════════════════════════════════════════════
    // XML Helpers
    // ═══════════════════════════════════════════════════════════════

    protected function startUrlset(): XMLWriter
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return $xml;
    }

    protected function addUrl(
        XMLWriter $xml,
        string $loc,
        string $lastmod,
        ?string $changefreq = null,
        ?string $priority = null,
    ): void {
        $xml->startElement('url');
        $xml->writeElement('loc', $loc);
        $xml->writeElement('lastmod', $lastmod);
        $xml->writeElement('changefreq', $changefreq ?? $this->changefreq());
        $xml->writeElement('priority', $priority ?? $this->priority());
        $xml->endElement();
    }

    protected function finishUrlset(XMLWriter $xml): string
    {
        $xml->endElement(); // urlset
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Build a URL by replacing placeholders in the pattern.
     *
     * @param  string               $baseUrl       e.g., "https://tangkiem.xyz"
     * @param  array<string,string>  $replacements  e.g., ['{slug}' => 'my-story']
     */
    protected function buildUrl(string $baseUrl, array $replacements): string
    {
        return $baseUrl . str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->urlPattern(),
        );
    }

    /**
     * Calculate the SQL offset for a given page number.
     */
    protected function offsetForPage(int $page): int
    {
        return ($page - 1) * $this->maxUrlsPerFile();
    }

    /**
     * Format a date for sitemap, with null safety.
     */
    protected function formatDate(mixed $date): string
    {
        if ($date && method_exists($date, 'toW3cString')) {
            return $date->toW3cString();
        }

        return now()->toW3cString();
    }
}

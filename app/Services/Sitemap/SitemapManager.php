<?php

declare(strict_types=1);

namespace App\Services\Sitemap;

use App\Services\Sitemap\Contracts\SitemapGeneratorInterface;
use App\Services\Sitemap\Generators\AuthorGenerator;
use App\Services\Sitemap\Generators\CategoryGenerator;
use App\Services\Sitemap\Generators\ChapterGenerator;
use App\Services\Sitemap\Generators\StaticPageGenerator;
use App\Services\Sitemap\Generators\StoryGenerator;
use App\Services\Sitemap\Generators\TagGenerator;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * Sitemap Manager — orchestrates generators and handles caching.
 *
 * This is the only class the controller interacts with.
 * Generators are registered in the GENERATORS constant below.
 *
 * To add a new entity type:
 * 1. Create a class implementing SitemapGeneratorInterface (extend AbstractGenerator)
 * 2. Add the class to the GENERATORS constant
 * 3. Add url_pattern/priority/changefreq in config/sitemap.php
 *
 * Usage:
 *   $manager = SitemapManager::forDomain('tangkiem.xyz');
 *   $xml = $manager->index();          // sitemap index
 *   $xml = $manager->sub('stories');    // sub-sitemap
 */
class SitemapManager
{
    /**
     * Cache TTL: 1 hour.
     */
    private const CACHE_TTL = 3600;

    protected string $baseUrl;

    /** @var SitemapGeneratorInterface[] */
    protected array $generators = [];

    /**
     * Registered generator classes, resolved in this order.
     *
     * @var array<int, class-string<SitemapGeneratorInterface>>
     */
    protected const GENERATORS = [
        StaticPageGenerator::class,
        StoryGenerator::class,
        ChapterGenerator::class,
        CategoryGenerator::class,
        AuthorGenerator::class,
        TagGenerator::class,
    ];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->bootGenerators();
    }

    /**
     * Create an instance for a specific frontend domain.
     */
    public static function forDomain(string $domain): self
    {
        $baseUrl = str_starts_with($domain, 'http')
            ? $domain
            : "https://{$domain}";

        return new self($baseUrl);
    }

    // ═══════════════════════════════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the sitemap index XML (cached).
     */
    public function index(): string
    {
        return Cache::remember(
            $this->cacheKey('index'),
            self::CACHE_TTL,
            fn () => $this->buildIndex(),
        );
    }

    /**
     * Get a sub-sitemap XML by name (cached).
     *
     * @param  string  $name  e.g. "stories", "chapters", "chapters-2"
     */
    public function sub(string $name): ?string
    {
        // Validate format
        if (! preg_match('/^[a-z]+(-\d+)?$/', $name)) {
            return null;
        }

        // Parse type and page: "chapters-2" → type="chapters", page=2
        [$type, $page] = $this->parseName($name);

        // Check if generator exists
        $generator = $this->getGenerator($type);

        if (! $generator) {
            return null;
        }

        return Cache::remember(
            $this->cacheKey($name),
            self::CACHE_TTL,
            fn () => $generator->build($this->baseUrl, $page),
        );
    }

    /**
     * Clear all cached sitemaps for this domain.
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey('index'));

        foreach ($this->generators as $generator) {
            $type = $generator->type();
            Cache::forget($this->cacheKey($type));

            // Clear paginated keys if applicable
            if ($generator->supportsPagination()) {
                $maxPages = (int) ceil($generator->totalCount() / $this->maxUrlsPerFile());

                for ($i = 2; $i <= max($maxPages, 10); $i++) {
                    Cache::forget($this->cacheKey("{$type}-{$i}"));
                }
            }
        }
    }

    /**
     * Get all registered generator types.
     *
     * @return string[]
     */
    public function getRegisteredTypes(): array
    {
        return array_map(fn ($g) => $g->type(), $this->generators);
    }

    // ═══════════════════════════════════════════════════════════════
    // Index Builder
    // ═══════════════════════════════════════════════════════════════

    protected function buildIndex(): string
    {
        $entries = [];
        $appUrl = rtrim((string) config('app.url'), '/');

        foreach ($this->generators as $generator) {
            $type = $generator->type();

            if ($generator->supportsPagination()) {
                // For paginated generators: use totalCount() directly
                // (avoids separate hasUrls/EXISTS + totalCount/COUNT queries)
                $totalCount = $generator->totalCount();

                if ($totalCount === 0) {
                    continue;
                }

                $entries[] = $type;
                $totalPages = (int) ceil($totalCount / $this->maxUrlsPerFile());

                for ($i = 2; $i <= $totalPages; $i++) {
                    $entries[] = "{$type}-{$i}";
                }
            } else {
                // For non-paginated: just check existence
                if (! $generator->hasUrls()) {
                    continue;
                }

                $entries[] = $type;
            }
        }

        // Build XML
        $now = now()->toW3cString();

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($entries as $name) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', "{$appUrl}/api/v1/sitemap-{$name}.xml");
            $xml->writeElement('lastmod', $now);
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    // ═══════════════════════════════════════════════════════════════
    // Generator Management
    // ═══════════════════════════════════════════════════════════════

    /**
     * Boot all generators.
     */
    protected function bootGenerators(): void
    {
        foreach (self::GENERATORS as $class) {
            $this->generators[] = app($class);
        }
    }

    /**
     * Find a generator by type name.
     */
    protected function getGenerator(string $type): ?SitemapGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->type() === $type) {
                return $generator;
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Parse a sitemap name into [type, page].
     *
     * "chapters"   → ["chapters", 1]
     * "chapters-2" → ["chapters", 2]
     *
     * @return array{0: string, 1: int}
     */
    protected function parseName(string $name): array
    {
        if (preg_match('/^([a-z]+)-(\d+)$/', $name, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$name, 1];
    }

    protected function cacheKey(string $name): string
    {
        $domain = parse_url($this->baseUrl, PHP_URL_HOST) ?? 'default';

        return "sitemap:{$domain}:{$name}";
    }

    protected function maxUrlsPerFile(): int
    {
        return (int) config('sitemap.max_urls_per_file', 45000);
    }
}

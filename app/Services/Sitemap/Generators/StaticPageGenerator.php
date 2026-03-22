<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

class StaticPageGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'pages';
    }

    public function supportsPagination(): bool
    {
        return false;
    }

    public function totalCount(): int
    {
        return count((array) config('sitemap.static_pages', []));
    }

    public function hasUrls(): bool
    {
        return $this->totalCount() > 0;
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $pages = (array) config('sitemap.static_pages', []);

        if (empty($pages)) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($pages as $entry) {
            $this->addUrl(
                $xml,
                rtrim($baseUrl, '/') . ($entry['path'] ?? '/'),
                now()->toW3cString(),
                $entry['changefreq'] ?? 'daily',
                $entry['priority'] ?? '1.0',
            );
        }

        return $this->finishUrlset($xml);
    }
}

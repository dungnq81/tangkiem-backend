<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Models\Author;

class AuthorGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'authors';
    }

    public function supportsPagination(): bool
    {
        return false;
    }

    public function totalCount(): int
    {
        return Author::active()->count();
    }

    public function hasUrls(): bool
    {
        return Author::active()->exists();
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $authors = Author::query()
            ->active()
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        if ($authors->isEmpty()) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($authors as $author) {
            $this->addUrl(
                $xml,
                $this->buildUrl($baseUrl, ['{slug}' => $author->slug]),
                $this->formatDate($author->updated_at),
            );
        }

        return $this->finishUrlset($xml);
    }
}

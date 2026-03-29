<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Models\Tag;

class TagGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'tags';
    }

    public function supportsPagination(): bool
    {
        return false;
    }

    public function totalCount(): int
    {
        return Tag::active()->count();
    }

    public function hasUrls(): bool
    {
        return Tag::active()->exists();
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $tags = Tag::query()
            ->active()
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        if ($tags->isEmpty()) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($tags as $tag) {
            $this->addUrl(
                $xml,
                $this->buildUrl($baseUrl, ['{slug}' => $tag->slug]),
                $this->formatDate($tag->updated_at),
            );
        }

        return $this->finishUrlset($xml);
    }
}

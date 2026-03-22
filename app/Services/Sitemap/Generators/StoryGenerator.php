<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Models\Story;

class StoryGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'stories';
    }

    public function supportsPagination(): bool
    {
        return true;
    }

    public function totalCount(): int
    {
        return Story::published()->count();
    }

    public function hasUrls(): bool
    {
        return Story::published()->exists();
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $stories = Story::query()
            ->published()
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->offset($this->offsetForPage($page))
            ->limit($this->maxUrlsPerFile())
            ->get();

        if ($stories->isEmpty()) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($stories as $story) {
            $this->addUrl(
                $xml,
                $this->buildUrl($baseUrl, ['{slug}' => $story->slug]),
                $this->formatDate($story->updated_at),
            );
        }

        return $this->finishUrlset($xml);
    }
}

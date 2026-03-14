<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Models\Chapter;

class ChapterGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'chapters';
    }

    public function supportsPagination(): bool
    {
        return true;
    }

    public function totalCount(): int
    {
        return $this->baseQuery()->count();
    }

    public function hasUrls(): bool
    {
        return $this->baseQuery()->exists();
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $chapters = $this->baseQuery()
            ->select([
                'chapters.slug as chapter_slug',
                'stories.slug as story_slug',
                'chapters.updated_at',
            ])
            ->orderBy('chapters.id')
            ->offset($this->offsetForPage($page))
            ->limit($this->maxUrlsPerFile())
            ->get();

        if ($chapters->isEmpty()) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($chapters as $row) {
            $this->addUrl(
                $xml,
                $this->buildUrl($baseUrl, [
                    '{storySlug}'   => $row->story_slug,
                    '{chapterSlug}' => $row->chapter_slug,
                ]),
                $this->formatDate($row->updated_at),
            );
        }

        return $this->finishUrlset($xml);
    }

    /**
     * Base query for published chapters with published, non-deleted stories.
     *
     * Uses explicit column qualification to avoid ambiguity in JOIN.
     */
    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Chapter::query()
            ->where('chapters.is_published', true)
            ->join('stories', 'chapters.story_id', '=', 'stories.id')
            ->where('stories.is_published', true)
            ->whereNull('stories.deleted_at');
    }
}

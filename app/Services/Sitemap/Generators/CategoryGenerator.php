<?php

declare(strict_types=1);

namespace App\Services\Sitemap\Generators;

use App\Models\Category;

class CategoryGenerator extends AbstractGenerator
{
    public function type(): string
    {
        return 'categories';
    }

    public function supportsPagination(): bool
    {
        return false;
    }

    public function totalCount(): int
    {
        return Category::active()->count();
    }

    public function hasUrls(): bool
    {
        return Category::active()->exists();
    }

    public function build(string $baseUrl, int $page = 1): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $categories = Category::query()
            ->active()
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            return null;
        }

        $xml = $this->startUrlset();

        foreach ($categories as $category) {
            $this->addUrl(
                $xml,
                $this->buildUrl($baseUrl, ['{slug}' => $category->slug]),
                $this->formatDate($category->updated_at),
            );
        }

        return $this->finishUrlset($xml);
    }
}

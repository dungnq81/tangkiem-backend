<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Author;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\Story;
use Awcodes\Curator\Models\Media;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContentStatsWidget extends BaseWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        // Single query for published count + total count via conditional aggregation
        $storyStats = Story::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published')
            ->first();

        $storyTotal = (int) $storyStats->total;
        $published = (int) $storyStats->published;
        $percentage = $storyTotal > 0 ? round(($published / $storyTotal) * 100) : 0;

        // Single query for word count + chapter count
        $chapterStats = Chapter::query()
            ->selectRaw('COALESCE(SUM(word_count), 0) as total_words')
            ->selectRaw('COUNT(*) as total_chapters')
            ->first();

        $totalWords = (int) $chapterStats->total_words;
        $chapterCount = (int) $chapterStats->total_chapters;

        // Format word count to readable number
        $formatted = match (true) {
            $totalWords >= 1_000_000_000 => number_format($totalWords / 1_000_000_000, 1) . 'B',
            $totalWords >= 1_000_000 => number_format($totalWords / 1_000_000, 1) . 'M',
            $totalWords >= 1_000 => number_format($totalWords / 1_000, 1) . 'K',
            default => number_format($totalWords),
        };

        $avgPerChapter = $chapterCount > 0
            ? number_format((int) ($totalWords / $chapterCount))
            : '0';

        // Batch remaining counts
        $authorCount = Author::count();
        $categoryCount = Category::count();
        $mediaCount = Media::count();

        return [
            Stat::make('Đã xuất bản', number_format($published))
                ->description("{$percentage}% tổng số truyện")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Tổng số từ', $formatted)
                ->description("TB {$avgPerChapter} từ/chương")
                ->descriptionIcon('heroicon-m-pencil-square')
                ->color('info'),

            Stat::make('Tác giả', number_format($authorCount))
                ->description("{$categoryCount} danh mục")
                ->descriptionIcon('heroicon-m-tag')
                ->color('warning'),

            Stat::make('Media', number_format($mediaCount))
                ->description('Tệp đa phương tiện')
                ->descriptionIcon('heroicon-m-photo')
                ->color('danger'),
        ];
    }
}

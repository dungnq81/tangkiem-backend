<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Story;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $now = now();
        $currentMonth = $now->month;
        $currentYear = $now->year;

        // Batch: get all counts + this-month counts in 4 queries (1 per model)
        [$storyTotal, $storyMonth] = $this->getCountWithMonthly(Story::class, $currentMonth, $currentYear);
        [$chapterTotal, $chapterMonth] = $this->getCountWithMonthly(Chapter::class, $currentMonth, $currentYear);
        [$userTotal, $userMonth] = $this->getCountWithMonthly(User::class, $currentMonth, $currentYear);
        [$commentTotal, $commentMonth] = $this->getCountWithMonthly(Comment::class, $currentMonth, $currentYear);

        // Batch: get 7-day trends in 4 queries (1 per model using GROUP BY)
        $storyTrend = $this->getLast7DaysTrend(Story::class);
        $chapterTrend = $this->getLast7DaysTrend(Chapter::class);
        $userTrend = $this->getLast7DaysTrend(User::class);
        $commentTrend = $this->getLast7DaysTrend(Comment::class);

        return [
            Stat::make('Tổng truyện', number_format($storyTotal))
                ->description("+{$storyMonth} tháng này")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($storyTrend)
                ->color('primary'),

            Stat::make('Tổng chương', number_format($chapterTotal))
                ->description("+{$chapterMonth} tháng này")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($chapterTrend)
                ->color('success'),

            Stat::make('Người dùng', number_format($userTotal))
                ->description("+{$userMonth} tháng này")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($userTrend)
                ->color('info'),

            Stat::make('Bình luận', number_format($commentTotal))
                ->description("+{$commentMonth} tháng này")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($commentTrend)
                ->color('warning'),
        ];
    }

    /**
     * Get total count + this month count in 1 query using conditional aggregation.
     *
     * @param  class-string  $model
     * @return array{0: int, 1: int}
     */
    private function getCountWithMonthly(string $model, int $month, int $year): array
    {
        $result = $model::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month', [$month, $year])
            ->first();

        return [(int) $result->total, (int) $result->this_month];
    }

    /**
     * Get last 7 days trend in 1 query using GROUP BY.
     *
     * @param  class-string  $model
     * @return array<int>
     */
    private function getLast7DaysTrend(string $model): array
    {
        $startDate = now()->subDays(6)->startOfDay();

        $counts = $model::query()
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('count', 'date')
            ->all();

        // Fill in missing days with 0
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $data[] = (int) ($counts[$date] ?? 0);
        }

        return $data;
    }
}

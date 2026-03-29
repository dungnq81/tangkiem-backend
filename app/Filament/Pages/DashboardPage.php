<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ActivityLog;
use App\Models\Author;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Story;
use App\Models\User;
use Awcodes\Curator\Models\Media;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Custom Dashboard Page — unified style with Analytics page.
 *
 * Replaces Filament built-in Dashboard + default StatsOverviewWidget.
 * Uses 'an-*' CSS class design system for visual consistency.
 */
class DashboardPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Bảng điều khiển';

    protected static string | UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = -2;

    protected static ?string $title = 'Bảng điều khiển';

    protected static ?string $slug = '/';

    protected string $view = 'filament.pages.dashboard';

    protected function getViewData(): array
    {
        $now = now();
        $month = $now->month;
        $year = $now->year;

        // ── Core counts (2 queries via conditional aggregation) ──
        $storyStats = Story::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month', [$month, $year])
            ->selectRaw('COALESCE(SUM(view_count), 0) as total_views')
            ->first();

        $chapterStats = Chapter::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month', [$month, $year])
            ->selectRaw('COALESCE(SUM(word_count), 0) as total_words')
            ->first();

        $userStats = User::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month', [$month, $year])
            ->first();

        $commentStats = Comment::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month', [$month, $year])
            ->first();

        // ── Secondary counts (3 simple queries) ──
        $authorCount = Author::count();
        $categoryCount = Category::count();
        $mediaCount = Media::count();

        // ── 7-day trends (4 queries) ──
        $storyTrend = $this->getLast7DaysTrend(Story::class);
        $chapterTrend = $this->getLast7DaysTrend(Chapter::class);
        $userTrend = $this->getLast7DaysTrend(User::class);
        $commentTrend = $this->getLast7DaysTrend(Comment::class);

        // ── Recent activity (1 query) ──
        $recentActivity = ActivityLog::query()
            ->latest('created_at')
            ->take(8)
            ->get(['created_at', 'event', 'description', 'subject_type', 'log_name']);

        // ── Format total words ──
        $totalWords = (int) $chapterStats->total_words;
        $formattedWords = match (true) {
            $totalWords >= 1_000_000_000 => number_format($totalWords / 1_000_000_000, 1) . 'B',
            $totalWords >= 1_000_000 => number_format($totalWords / 1_000_000, 1) . 'M',
            $totalWords >= 1_000 => number_format($totalWords / 1_000, 1) . 'K',
            default => number_format($totalWords),
        };

        $chapterCount = (int) $chapterStats->total;
        $avgWordsPerChapter = $chapterCount > 0 ? number_format((int) ($totalWords / $chapterCount)) : '0';

        // ── Published percentage ──
        $storyTotal = (int) $storyStats->total;
        $published = (int) $storyStats->published;
        $publishedPct = $storyTotal > 0 ? round(($published / $storyTotal) * 100) : 0;

        return [
            'storyTotal'     => $storyTotal,
            'storyMonth'     => (int) $storyStats->this_month,
            'storyPublished' => $published,
            'publishedPct'   => $publishedPct,
            'totalViews'     => (int) $storyStats->total_views,

            'chapterTotal'   => $chapterCount,
            'chapterMonth'   => (int) $chapterStats->this_month,
            'formattedWords' => $formattedWords,
            'avgWords'       => $avgWordsPerChapter,

            'userTotal'      => (int) $userStats->total,
            'userMonth'      => (int) $userStats->this_month,

            'commentTotal'   => (int) $commentStats->total,
            'commentMonth'   => (int) $commentStats->this_month,

            'authorCount'    => $authorCount,
            'categoryCount'  => $categoryCount,
            'mediaCount'     => $mediaCount,

            'storyTrend'     => $storyTrend,
            'chapterTrend'   => $chapterTrend,
            'userTrend'      => $userTrend,
            'commentTrend'   => $commentTrend,

            'recentActivity' => $recentActivity,

            'greeting'       => $this->getGreeting(),
            'userName'       => auth()->user()?->name ?? 'Quản trị viên',
            'formattedDate'  => $this->getFormattedDate(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function getGreeting(): string
    {
        $hour = (int) now()->format('H');

        return match (true) {
            $hour >= 5 && $hour < 12  => 'Chào buổi sáng',
            $hour >= 12 && $hour < 18 => 'Chào buổi chiều',
            $hour >= 18 && $hour < 22 => 'Chào buổi tối',
            default                   => 'Xin chào',
        };
    }

    private function getFormattedDate(): string
    {
        $dayOfWeek = match ((int) now()->dayOfWeek) {
            0 => 'Chủ nhật', 1 => 'Thứ hai', 2 => 'Thứ ba',
            3 => 'Thứ tư',   4 => 'Thứ năm', 5 => 'Thứ sáu',
            6 => 'Thứ bảy',
        };

        return $dayOfWeek . ', ' . now()->format('d/m/Y');
    }

    /**
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

        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $data[] = (int) ($counts[$date] ?? 0);
        }

        return $data;
    }

    /**
     * Format large numbers.
     */
    public static function formatNumber(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 => number_format($number / 1_000_000, 1) . 'M',
            $number >= 10_000   => number_format($number / 1_000, 1) . 'K',
            default             => number_format($number),
        };
    }
}

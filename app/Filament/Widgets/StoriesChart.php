<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Story;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class StoriesChart extends ChartWidget
{
    protected ?string $heading = 'Truyện mới theo tháng';

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = '300s';

    protected string $color = 'primary';

    protected function getData(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        // Single query: GROUP BY month/year, with conditional count for published
        $results = Story::query()
            ->select(
                DB::raw('YEAR(created_at) as y'),
                DB::raw('MONTH(created_at) as m'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published'),
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
            ->orderBy(DB::raw('YEAR(created_at)'))
            ->orderBy(DB::raw('MONTH(created_at)'))
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . str_pad((string) $row->m, 2, '0', STR_PAD_LEFT));

        $months = [];
        $storyCounts = [];
        $publishedCounts = [];

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');
            $months[] = $date->format('m/Y');
            $storyCounts[] = (int) ($results[$key]?->total ?? 0);
            $publishedCounts[] = (int) ($results[$key]?->published ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Tổng truyện mới',
                    'data' => $storyCounts,
                    'borderColor' => 'rgba(99, 102, 241, 1)',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Đã xuất bản',
                    'data' => $publishedCounts,
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $months,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}

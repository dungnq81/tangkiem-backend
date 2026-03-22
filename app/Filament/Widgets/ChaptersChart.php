<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Chapter;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class ChaptersChart extends ChartWidget
{
    protected ?string $heading = 'Chương mới theo tháng';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = [
        'sm' => 'full',
        'md' => 1,
        'xl' => 1,
    ];

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = '300s';

    protected string $color = 'success';

    protected function getData(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        // Single query: GROUP BY month/year
        $results = Chapter::query()
            ->select(
                DB::raw('YEAR(created_at) as y'),
                DB::raw('MONTH(created_at) as m'),
                DB::raw('COUNT(*) as total'),
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
            ->orderBy(DB::raw('YEAR(created_at)'))
            ->orderBy(DB::raw('MONTH(created_at)'))
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . str_pad((string) $row->m, 2, '0', STR_PAD_LEFT));

        $months = [];
        $chapterCounts = [];

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');
            $months[] = $date->format('m/Y');
            $chapterCounts[] = (int) ($results[$key]?->total ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Chương mới',
                    'data' => $chapterCounts,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.7)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 1,
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $months,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
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

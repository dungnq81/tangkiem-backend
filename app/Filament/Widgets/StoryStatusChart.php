<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\StoryStatus;
use App\Models\Story;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class StoryStatusChart extends ChartWidget
{
    protected ?string $heading = 'Phân bố trạng thái truyện';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = [
        'sm' => 'full',
        'md' => 1,
        'xl' => 1,
    ];

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = '300s';

    protected function getData(): array
    {
        // Single query: GROUP BY status
        $counts = Story::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $colorMap = [
            'ongoing' => 'rgba(99, 102, 241, 0.8)',
            'completed' => 'rgba(16, 185, 129, 0.8)',
            'hiatus' => 'rgba(245, 158, 11, 0.8)',
            'dropped' => 'rgba(239, 68, 68, 0.8)',
        ];

        $borderColorMap = [
            'ongoing' => 'rgba(99, 102, 241, 1)',
            'completed' => 'rgba(16, 185, 129, 1)',
            'hiatus' => 'rgba(245, 158, 11, 1)',
            'dropped' => 'rgba(239, 68, 68, 1)',
        ];

        $labels = [];
        $data = [];
        $colors = [];
        $borderColors = [];

        foreach (StoryStatus::cases() as $status) {
            $labels[] = $status->label();
            $data[] = (int) ($counts[$status->value] ?? 0);
            $colors[] = $colorMap[$status->value] ?? 'rgba(148, 163, 184, 0.8)';
            $borderColors[] = $borderColorMap[$status->value] ?? 'rgba(148, 163, 184, 1)';
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => $borderColors,
                    'borderWidth' => 2,
                    'hoverOffset' => 8,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'cutout' => '60%',
        ];
    }
}

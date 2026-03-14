<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentActivityWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Hoạt động gần đây')
            ->query(
                ActivityLog::query()
                    ->latest('created_at')
                    ->take(10)
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->since()
                    ->sortable()
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->width('140px'),

                TextColumn::make('event')
                    ->label('Sự kiện')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'created' => 'heroicon-o-plus-circle',
                        'updated' => 'heroicon-o-pencil-square',
                        'deleted' => 'heroicon-o-trash',
                        default => 'heroicon-o-bolt',
                    })
                    ->width('120px'),

                TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('subject_type')
                    ->label('Đối tượng')
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '—';
                        }

                        $map = [
                            'App\\Models\\Story' => 'Truyện',
                            'App\\Models\\Chapter' => 'Chương',
                            'App\\Models\\User' => 'Người dùng',
                            'App\\Models\\Comment' => 'Bình luận',
                            'App\\Models\\Category' => 'Danh mục',
                            'App\\Models\\Tag' => 'Tag',
                            'App\\Models\\Author' => 'Tác giả',
                            'App\\Models\\ScrapeJob' => 'Công việc thu thập',
                            'App\\Models\\ScrapeSource' => 'Nguồn thu thập',
                        ];

                        return $map[$state] ?? class_basename($state);
                    })
                    ->badge()
                    ->color('gray')
                    ->width('140px'),

                TextColumn::make('log_name')
                    ->label('Nhật ký')
                    ->badge()
                    ->color('gray')
                    ->width('100px'),
            ])
            ->paginated(false)
            ->striped()
            ->emptyStateHeading('Chưa có hoạt động nào')
            ->emptyStateDescription('Các hoạt động sẽ được ghi nhận tại đây.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}

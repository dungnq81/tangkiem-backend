<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chapters\Tables;

use App\Filament\Resources\Chapters\ChapterResource;
use App\Models\Chapter;
use App\Services\Ai\AiContentCleaner;
use App\Services\Ai\AiService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Support\Enums\Width;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class ChaptersTable
{
    /**
     * Generate URL to Chapters page filtered by story and volume.
     */
    private static function getVolumeFilterUrl(int $storyId, int $volumeNumber): string
    {
        $baseUrl = ChapterResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'story_id' => ['value' => $storyId],
                'volume_number' => ['value' => $volumeNumber],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    /**
     * Generate URL to Chapters page filtered by story.
     */
    private static function getStoryFilterUrl(int $storyId): string
    {
        $baseUrl = ChapterResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'story_id' => ['value' => $storyId],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                // Eager load story to fix N+1 in grouping & columns
                $query->with('story:id,title');

                $volumeNumber = request()->input('filters.volume_number.value');
                if (filled($volumeNumber)) {
                    $query->where('volume_number', (int) $volumeNumber);
                }
                return $query;
            })
            ->groups([
                Group::make('story.title')
                    ->label('Truyện')
                    ->collapsible(),
            ])
            ->defaultGroup('story.title')
            ->striped()
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->placeholder('(Không có tiêu đề)')
                    ->url(fn (Chapter $record): string => ChapterResource::getUrl('edit', ['record' => $record])),

                TextColumn::make('story.title')
                    ->label('Truyện')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->url(fn (Chapter $record): string => self::getStoryFilterUrl($record->story_id))
                    ->tooltip('Lọc chương theo truyện này'),

                TextColumn::make('volume_number')
                    ->label('Quyển')
                    ->sortable()
                    ->alignCenter()
                    ->getStateUsing(function (Chapter $record): ?HtmlString {
                        if ($record->volume_number <= 1) {
                            return null;
                        }

                        $url = self::getVolumeFilterUrl($record->story_id, $record->volume_number);
                        $label = "Q.{$record->volume_number}";

                        return new HtmlString(sprintf(
                            '<a href="%s" class="fi-badge inline-flex items-center rounded-md text-xs font-medium ring-1 ring-inset px-1.5 py-0.5 transition-opacity hover:opacity-80" style="background-color: rgba(139, 92, 246, 0.1); color: rgb(139, 92, 246); border-color: rgba(139, 92, 246, 0.3);">%s</a>',
                            $url,
                            e($label)
                        ));
                    })
                    ->html()
                    ->placeholder('-')
                    ->toggleable()
                    ->tooltip('Lọc chương theo quyển này'),

                TextColumn::make('chapter_number')
                    ->label('Chương')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy('sort_key', $direction);
                    })
                    ->alignCenter()
                    ->formatStateUsing(fn (Chapter $record): string =>
                        $record->formatted_number),

                TextColumn::make('word_count')
                    ->label('Số từ')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->color('gray'),

                TextColumn::make('view_count')
                    ->label('Lượt xem')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('gray'),

                IconColumn::make('is_published')
                    ->label('Xuất bản')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckCircle)
                    ->falseIcon(Heroicon::OutlinedClock)
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_published ? 'Đã xuất bản' : 'Chưa xuất bản'),

                IconColumn::make('is_vip')
                    ->label('VIP')
                    ->boolean()
                    ->trueIcon(Heroicon::Sparkles)
                    ->falseIcon(Heroicon::OutlinedSparkles)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_vip ? 'Chương VIP' : 'Chương miễn phí'),

                TextColumn::make('published_at')
                    ->label('Ngày xuất bản')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('scheduled_at')
                    ->label('Lên lịch')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('story_id')
                    ->label('Truyện')
                    ->relationship('story', 'title')
                    ->searchable(),

                Filter::make('volume_number')
                    ->label('Quyển')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('volume_number')
                            ->label('Số quyển')
                            ->numeric()
                            ->placeholder('Nhập số quyển'),
                    ])
                    ->query(function ($query, array $data) {
                        if (filled($data['volume_number'])) {
                            $query->where('volume_number', (int) $data['volume_number']);
                        }
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (filled($data['volume_number'])) {
                            return "Quyển {$data['volume_number']}";
                        }
                        return null;
                    }),

                TernaryFilter::make('is_published')
                    ->label('Xuất bản')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã xuất bản')
                    ->falseLabel('Chưa xuất bản'),

                TernaryFilter::make('is_vip')
                    ->label('VIP')
                    ->placeholder('Tất cả')
                    ->trueLabel('Chương VIP')
                    ->falseLabel('Chương miễn phí'),

                TrashedFilter::make()
                    ->label('Thùng rác')
                    ->placeholder('Chưa xóa')
                    ->trueLabel('Tất cả')
                    ->falseLabel('Chỉ đã xóa'),
            ], layout: FiltersLayout::Dropdown)
            ->filtersFormColumns(3)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->columnToggleFormColumns(2)
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Chỉnh sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),

                    Action::make('publish')
                        ->label('Xuất bản')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn ($record) => !$record->trashed() && !$record->is_published)
                        ->requiresConfirmation()
                        ->modalHeading('Xuất bản chương')
                        ->modalDescription('Bạn có chắc muốn xuất bản chương này?')
                        ->action(fn ($record) => $record->update(['is_published' => true, 'published_at' => now()])),

                    Action::make('unpublish')
                        ->label('Hủy xuất bản')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn ($record) => !$record->trashed() && $record->is_published)
                        ->requiresConfirmation()
                        ->modalHeading('Hủy xuất bản chương')
                        ->modalDescription('Bạn có chắc muốn hủy xuất bản chương này?')
                        ->action(fn ($record) => $record->update(['is_published' => false])),

                    Action::make('toggle_vip')
                        ->label(fn ($record) => $record->is_vip ? 'Bỏ VIP' : 'Đặt VIP')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->color('warning')
                        ->visible(fn ($record) => !$record->trashed())
                        ->action(fn ($record) => $record->update(['is_vip' => !$record->is_vip])),

                    RestoreAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),

                    DeleteAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning')
                        ->modalHeading('Cho vào thùng rác')
                        ->modalDescription('Chương sẽ được chuyển vào thùng rác. Bạn có thể khôi phục sau.'),

                    ForceDeleteAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalHeading('Xóa vĩnh viễn')
                        ->modalDescription('Chương sẽ bị xóa HOÀN TOÀN khỏi hệ thống. Hành động này không thể hoàn tác!'),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publishAll')
                        ->label('Xuất bản')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Xuất bản các chương đã chọn')
                        ->modalDescription('Bạn có chắc muốn xuất bản tất cả các chương được chọn?')
                        ->action(fn (Collection $records) => $records->each->update([
                            'is_published' => true,
                            'published_at' => now(),
                        ]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unpublishAll')
                        ->label('Hủy xuất bản')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy xuất bản các chương đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy xuất bản tất cả các chương được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_published' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setVip')
                        ->label('Đặt VIP')
                        ->icon(Heroicon::Sparkles)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Đặt VIP các chương đã chọn')
                        ->action(fn (Collection $records) => $records->each->update(['is_vip' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('removeVip')
                        ->label('Bỏ VIP')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Bỏ VIP các chương đã chọn')
                        ->action(fn (Collection $records) => $records->each->update(['is_vip' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('aiClean')
                        ->label('🤖 AI Dọn dẹp')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->color('info')
                        ->visible(fn (): bool => AiService::isEnabled('content_clean'))
                        ->form([
                            Select::make('mode')
                                ->label('Chế độ dọn dẹp')
                                ->options([
                                    'patterns_only' => '⚡ Patterns + Regex (miễn phí)',
                                    'ai'            => '🤖 Patterns + Regex + AI (tốn tokens)',
                                ])
                                ->default('patterns_only')
                                ->required()
                                ->native(false),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Dọn dẹp nội dung hàng loạt')
                        ->modalDescription('Dọn dẹp nội dung các chương đã chọn. Quá trình có thể mất vài giây/chương.')
                        ->action(function (Collection $records, array $data): void {
                            // Eager load content to prevent N+1 queries
                            $records->load('content');
                            $cleaner = app(AiContentCleaner::class);
                            $useAi = ($data['mode'] ?? 'patterns_only') === 'ai';
                            $cleaned = 0;
                            $skipped = 0;
                            $totalDiff = 0;

                            foreach ($records as $chapter) {
                                $chapterContent = $chapter->content;

                                if (! $chapterContent || empty($chapterContent->content)) {
                                    $skipped++;
                                    continue;
                                }

                                $original = $chapterContent->content;
                                $result = $cleaner->clean(
                                    $original,
                                    $chapter->scrape_source_id,
                                    $useAi,
                                );

                                $diff = mb_strlen($original) - mb_strlen($result);

                                if ($diff > 0) {
                                    $chapterContent->update([
                                        'content'      => $result,
                                        'content_hash' => md5($result),
                                        'byte_size'    => strlen($result),
                                    ]);
                                    $cleaned++;
                                    $totalDiff += $diff;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title('Dọn dẹp hoàn tất')
                                ->body("✅ Dọn: {$cleaned} chương | ⏭️ Bỏ qua: {$skipped} | 🗑️ Xóa: " . number_format($totalDiff) . ' ký tự')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning'),

                    ForceDeleteBulkAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalDescription('Các chương được chọn sẽ bị xóa HOÀN TOÀN. Không thể hoàn tác!'),

                    RestoreBulkAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),
                ]),
            ])
            ->emptyStateHeading('Không có chương nào')
            ->emptyStateDescription('Chọn truyện từ bộ lọc phía trên để xem danh sách chương, hoặc tạo chương mới.')
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('sort_key', 'asc');
    }
}

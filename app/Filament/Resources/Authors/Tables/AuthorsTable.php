<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authors\Tables;

use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\Stories\StoryResource;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Awcodes\Curator\Components\Tables\CuratorColumn;

class AuthorsTable
{
    /**
     * Generate URL to Stories page filtered by author.
     */
    private static function getStoriesFilterUrl(int $authorId): string
    {
        $baseUrl = StoryResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'author_id' => ['value' => $authorId],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with('avatar')
                ->withCount('stories')
                ->withSum('stories', 'total_chapters')
                ->withSum('stories', 'view_count'))
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                CuratorColumn::make('avatar')
                    ->label('🖼️')
                    ->size(50)
                    ->ring(2)
                    ->overlap(2)
                    ->limit(1)
					->url(fn ($record) => AuthorResource::getUrl('edit', ['record' => $record]))
                    ->extraAttributes(['class' => 'py-2'])
                    ->placeholder('👤')
                    ->alignCenter(),

                TextColumn::make('name')
                    ->label('Tên tác giả')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => AuthorResource::getUrl('edit', ['record' => $record]))
                    ->description(fn ($record) => $record->original_name)
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->copyMessage('Đã copy!')
                    ->color('gray'),

                TextColumn::make('stories_count')
                    ->label('Số truyện')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->url(fn ($record) => self::getStoriesFilterUrl($record->id)),

                TextColumn::make('stories_sum_total_chapters')
                    ->label('Số chương')
                    ->numeric()
                    ->default(0)
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format((int) ($state ?? 0)))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('stories_sum_view_count')
                    ->label('Lượt xem')
                    ->numeric()
                    ->default(0)
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format((int) ($state ?? 0))),

                    IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_active ? 'Đang kích hoạt' : 'Đã vô hiệu hóa'),

                IconColumn::make('is_verified')
                    ->label('Xác minh')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_verified ? 'Đã xác minh' : 'Chưa xác minh'),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->label('Đã xóa')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Trạng thái')
                    ->options([
                        1 => 'Kích hoạt',
                        0 => 'Vô hiệu hóa',
                    ]),

                SelectFilter::make('is_verified')
                    ->label('Xác minh')
                    ->options([
                        1 => 'Đã xác minh',
                        0 => 'Chưa xác minh',
                    ]),

                TrashedFilter::make()
                    ->label('Thùng rác')
                    ->placeholder('Chưa xóa')
                    ->trueLabel('Tất cả')
                    ->falseLabel('Chỉ đã xóa'),
            ])
            ->filtersFormColumns(2)
            ->columnToggleFormColumns(2)
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Chỉnh sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),

                    Action::make('activate')
                        ->label('Kích hoạt')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn ($record) => !$record->trashed() && !$record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Kích hoạt tác giả')
                        ->modalDescription('Bạn có chắc muốn kích hoạt tác giả này?')
                        ->action(fn ($record) => $record->update(['is_active' => true])),

                    Action::make('deactivate')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn ($record) => !$record->trashed() && $record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt tác giả')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt tác giả này?')
                        ->action(fn ($record) => $record->update(['is_active' => false])),

                    Action::make('verify')
                        ->label('Xác minh')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->color('primary')
                        ->visible(fn ($record) => !$record->trashed() && !$record->is_verified)
                        ->action(fn ($record) => $record->update(['is_verified' => true])),

                    Action::make('unverify')
                        ->label('Hủy xác minh')
                        ->icon(Heroicon::OutlinedShieldExclamation)
                        ->color('gray')
                        ->visible(fn ($record) => !$record->trashed() && $record->is_verified)
                        ->action(fn ($record) => $record->update(['is_verified' => false])),

                    RestoreAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),

                    DeleteAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning')
                        ->modalHeading('Cho vào thùng rác')
                        ->modalDescription('Tác giả sẽ được chuyển vào thùng rác. Bạn có thể khôi phục sau.'),

                    ForceDeleteAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalHeading('Xóa vĩnh viễn')
                        ->modalDescription('Tác giả sẽ bị xóa HOÀN TOÀN khỏi hệ thống. Hành động này không thể hoàn tác!'),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activateAll')
                        ->label('Kích hoạt')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Kích hoạt các tác giả đã chọn')
                        ->modalDescription('Bạn có chắc muốn kích hoạt tất cả các tác giả được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivateAll')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt các tác giả đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt tất cả các tác giả được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('verifyAll')
                        ->label('Xác minh')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Xác minh các tác giả đã chọn')
                        ->modalDescription('Bạn có chắc muốn xác minh tất cả các tác giả được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_verified' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unverifyAll')
                        ->label('Hủy xác minh')
                        ->icon(Heroicon::OutlinedShieldExclamation)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy xác minh các tác giả đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy xác minh tất cả các tác giả được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_verified' => false]))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning'),

                    ForceDeleteBulkAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalDescription('Các tác giả được chọn sẽ bị xóa HOÀN TOÀN. Không thể hoàn tác!'),

                    RestoreBulkAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('created_at', 'desc');
    }
}

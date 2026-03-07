<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Tables;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Stories\StoryResource;
use App\Models\Category;
use Awcodes\Curator\Components\Tables\CuratorColumn;
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
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CategoriesTable
{
    /**
     * Generate URL to Stories page filtered by category.
     */
    private static function getStoriesFilterUrl(int $categoryId): string
    {
        $baseUrl = StoryResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'primary_category_id' => ['value' => $categoryId],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('image')->withCount('stories'))
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                CuratorColumn::make('image')
                    ->label('🖼️')
                    ->size(50)
                    ->ring(2)
                    ->overlap(2)
                    ->limit(1)
					->url(fn ($record) => CategoryResource::getUrl('edit', ['record' => $record]))
                    ->extraAttributes(['class' => 'py-2'])
                    ->alignCenter(),

                TextColumn::make('name')
                    ->label('Tên thể loại')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn ($record) => CategoryResource::getUrl('edit', ['record' => $record]))
                    ->description(fn (Category $record): ?string =>
                        $record->parent ? "↳ {$record->parent->name}" : null),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->copyMessage('Đã copy!')
                    ->color('gray'),

                TextColumn::make('depth')
                    ->label('Cấp')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => match ($state) {
                        0 => 'primary',
                        1 => 'info',
                        default => 'gray',
                    }),

                ColorColumn::make('color')
                    ->label('Màu')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('stories_count')
                    ->label('Số truyện')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->url(fn (Category $record) => self::getStoriesFilterUrl($record->id)),

                TextColumn::make('children_count')
                    ->label('TL con')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_active ? 'Đang hoạt động' : 'Đã ẩn'),

                IconColumn::make('is_featured')
                    ->label('Nổi bật')
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->falseIcon(Heroicon::OutlinedStar)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_featured ? 'Thể loại nổi bật' : 'Thể loại thường'),

                IconColumn::make('show_in_menu')
                    ->label('Menu')
                    ->boolean()
                    ->trueIcon(Heroicon::Bars3)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->show_in_menu ? 'Hiển thị trong menu' : 'Ẩn khỏi menu')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
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
                SelectFilter::make('parent_id')
                    ->label('Thể loại cha')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Tất cả'),

                SelectFilter::make('depth')
                    ->label('Cấp độ')
                    ->options([
                        0 => 'Gốc (Cấp 0)',
                        1 => 'Cấp 1',
                        2 => 'Cấp 2+',
                    ]),

                SelectFilter::make('is_active')
                    ->label('Trạng thái')
                    ->options([
                        1 => 'Đang hoạt động',
                        0 => 'Đã ẩn',
                    ]),

                SelectFilter::make('is_featured')
                    ->label('Nổi bật')
                    ->options([
                        1 => 'Nổi bật',
                        0 => 'Thường',
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
                        ->modalHeading('Kích hoạt thể loại')
                        ->modalDescription('Bạn có chắc muốn kích hoạt thể loại này?')
                        ->action(fn ($record) => $record->update(['is_active' => true])),

                    Action::make('deactivate')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn ($record) => !$record->trashed() && $record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt thể loại')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt thể loại này?')
                        ->action(fn ($record) => $record->update(['is_active' => false])),

                    Action::make('toggle_featured')
                        ->label(fn ($record) => $record->is_featured ? 'Bỏ nổi bật' : 'Đánh dấu nổi bật')
                        ->icon(Heroicon::OutlinedStar)
                        ->color('warning')
                        ->visible(fn ($record) => !$record->trashed())
                        ->action(fn ($record) => $record->update(['is_featured' => !$record->is_featured])),

                    Action::make('show_in_menu')
                        ->label('Hiển thị menu')
                        ->icon(Heroicon::OutlinedBars3)
                        ->color('info')
                        ->visible(fn ($record) => !$record->trashed() && !$record->show_in_menu)
                        ->action(fn ($record) => $record->update(['show_in_menu' => true])),

                    Action::make('hide_from_menu')
                        ->label('Ẩn menu')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('gray')
                        ->visible(fn ($record) => !$record->trashed() && $record->show_in_menu)
                        ->action(fn ($record) => $record->update(['show_in_menu' => false])),

                    RestoreAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),

                    DeleteAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning')
                        ->modalHeading('Cho vào thùng rác')
                        ->modalDescription('Thể loại sẽ được chuyển vào thùng rác. Bạn có thể khôi phục sau.'),

                    ForceDeleteAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalHeading('Xóa vĩnh viễn')
                        ->modalDescription('Thể loại sẽ bị xóa HOÀN TOÀN khỏi hệ thống. Hành động này không thể hoàn tác!'),
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
                        ->modalHeading('Kích hoạt các thể loại đã chọn')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivateAll')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt các thể loại đã chọn')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('featureAll')
                        ->label('Đánh dấu nổi bật')
                        ->icon(Heroicon::Star)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Đánh dấu nổi bật')
                        ->action(fn (Collection $records) => $records->each->update(['is_featured' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unfeatureAll')
                        ->label('Bỏ nổi bật')
                        ->icon(Heroicon::OutlinedStar)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Bỏ nổi bật')
                        ->action(fn (Collection $records) => $records->each->update(['is_featured' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('showInMenu')
                        ->label('Hiển thị menu')
                        ->icon(Heroicon::OutlinedBars3)
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['show_in_menu' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('hideFromMenu')
                        ->label('Ẩn menu')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['show_in_menu' => false]))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->label('Cho vào thùng rác')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('warning'),

                    ForceDeleteBulkAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->modalDescription('Các thể loại được chọn sẽ bị xóa HOÀN TOÀN. Không thể hoàn tác!'),

                    RestoreBulkAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('sort_order', 'asc');
    }
}

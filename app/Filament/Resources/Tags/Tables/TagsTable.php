<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Tables;

use App\Enums\TagType;
use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('stories'))
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên thẻ')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn ($record) => TagResource::getUrl('edit', ['record' => $record])),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->copyMessage('Đã copy!')
                    ->color('gray'),

                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (TagType $state): string => $state->label())
                    ->color(fn (TagType $state): string => $state->color())
                    ->icon(fn (TagType $state): string => $state->icon())
                    ->sortable(),

                ColorColumn::make('color')
                    ->label('Màu')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('stories_count')
                    ->label('Số truyện')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge(),

                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_active ? 'Đang kích hoạt' : 'Đã vô hiệu hóa')
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại thẻ')
                    ->options(TagType::options())
                    ->native(false),

                SelectFilter::make('is_active')
                    ->label('Trạng thái')
                    ->options([
                        1 => 'Kích hoạt',
                        0 => 'Vô hiệu hóa',
                    ]),
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
                        ->visible(fn ($record) => !$record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Kích hoạt thẻ')
                        ->modalDescription('Bạn có chắc muốn kích hoạt thẻ này?')
                        ->action(fn ($record) => $record->update(['is_active' => true])),

                    Action::make('deactivate')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn ($record) => $record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt thẻ')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt thẻ này?')
                        ->action(fn ($record) => $record->update(['is_active' => false])),

                    DeleteAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->modalHeading('Xóa thẻ')
                        ->modalDescription('Bạn có chắc muốn xóa thẻ này? Hành động này không thể hoàn tác.'),
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
                        ->modalHeading('Kích hoạt các thẻ đã chọn')
                        ->modalDescription('Bạn có chắc muốn kích hoạt tất cả các thẻ được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivateAll')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt các thẻ đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt tất cả các thẻ được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('changeType')
                        ->label('Đổi loại thẻ')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('info')
                        ->form([
                            Select::make('type')
                                ->label('Loại thẻ mới')
                                ->options(TagType::options())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each->update(['type' => $data['type']]);
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('created_at', 'desc');
    }
}

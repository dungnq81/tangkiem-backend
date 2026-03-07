<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources\Tables;

use App\Filament\Resources\ScrapeSources\ScrapeSourceResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ScrapeSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->columns([
                self::nameColumn(),
                self::baseUrlColumn(),
                self::renderTypeColumn(),
                self::delayColumn(),
                self::jobsCountColumn(),
                self::activeColumn(),
                self::createdAtColumn(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái'),
                TrashedFilter::make()
                    ->label('Thùng rác')
                    ->placeholder('Chưa xóa')
                    ->trueLabel('Tất cả')
                    ->falseLabel('Chỉ đã xóa'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),
                    Action::make('cloneSource')
                        ->label('Clone')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Clone nguồn thu thập?')
                        ->modalDescription(fn ($record) => "Tạo bản sao của \"{$record->name}\" với toàn bộ cấu hình.")
                        ->action(function ($record) {
                            $clone = $record->replicate(['created_at', 'updated_at', 'deleted_at', 'jobs_count']);
                            $clone->name = $record->name . ' (Copy)';
                            $clone->save();

                            Notification::make()
                                ->title('Đã clone nguồn')
                                ->body("Nguồn \"{$clone->name}\" đã được tạo.")
                                ->success()
                                ->send();

                            return redirect(ScrapeSourceResource::getUrl('edit', ['record' => $clone]));
                        }),
                    RestoreAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),
                    DeleteAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                    ForceDeleteAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger'),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                    ForceDeleteBulkAction::make()
                        ->label('Xóa vĩnh viễn')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger'),
                    RestoreBulkAction::make()
                        ->label('Khôi phục')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('info'),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Columns
    // ═══════════════════════════════════════════════════════════════════════

    private static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->label('Tên')
            ->searchable()
            ->sortable()
            ->weight('bold')
            ->url(fn ($record) => ScrapeSourceResource::getUrl('edit', ['record' => $record]));
    }

    private static function baseUrlColumn(): TextColumn
    {
        return TextColumn::make('base_url')
            ->label('URL')
            ->limit(40)
            ->url(fn ($record) => $record->base_url, shouldOpenInNewTab: true);
    }

    private static function renderTypeColumn(): TextColumn
    {
        return TextColumn::make('render_type')
            ->label('Loại')
            ->badge()
            ->color(fn (string $state) => match ($state) {
                'ssr' => 'success',
                'spa' => 'warning',
                default => 'gray',
            })
            ->formatStateUsing(fn (string $state) => match ($state) {
                'ssr' => 'cURL',
                'spa' => 'Browser',
                default => $state,
            });
    }

    private static function delayColumn(): TextColumn
    {
        return TextColumn::make('delay_ms')
            ->label('Delay')
            ->suffix('ms')
            ->sortable();
    }

    private static function jobsCountColumn(): TextColumn
    {
        return TextColumn::make('jobs_count')
            ->label('Jobs')
            ->counts('jobs')
            ->sortable();
    }

    private static function activeColumn(): ToggleColumn
    {
        return ToggleColumn::make('is_active')
            ->label('Active');
    }

    private static function createdAtColumn(): TextColumn
    {
        return TextColumn::make('created_at')
            ->label('Tạo lúc')
            ->dateTime('d/m/Y H:i')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);
    }
}

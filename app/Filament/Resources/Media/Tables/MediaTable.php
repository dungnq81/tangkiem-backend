<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use Awcodes\Curator\Components\Tables\CuratorColumn;
use Awcodes\Curator\Facades\Curator;
use Exception;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaTable
{
    /**
     * Safely get the layoutView property from the Livewire component.
     * Falls back to 'list' if the property doesn't exist (e.g., when used in non-ListMedia contexts).
     */
    protected static function getLayoutView(object $livewire): string
    {
        return property_exists($livewire, 'layoutView') ? $livewire->layoutView : 'list';
    }

    /** @throws Exception */
    public static function configure(Table $table): Table
    {
        $livewire = $table->getLivewire();
        $layoutView = static::getLayoutView($livewire);

        return $table
            ->columns(
                $layoutView === 'grid'
                    ? static::getDefaultGridTableColumns()
                    : static::getDefaultTableColumns(),
            )
            ->searchable(['title', 'caption', 'description'])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            // === Custom grid columns: 3/4/6 thay vì 2/3/4 ===
            ->contentGrid(function () use ($layoutView): ?array {
                if ($layoutView === 'grid') {
                    return [
                        'md' => 3,
                        'lg' => 4,
                        'xl' => 6,
                    ];
                }

                return null;
            })
            ->defaultPaginationPageOption(24)
            ->paginationPageOptions([12, 24, 48, 96, 'all'])
            ->recordUrl(null);
    }

    /** @throws Exception */
    public static function getDefaultTableColumns(): array
    {
        return [
            CuratorColumn::make('url')
                ->label(trans('curator::tables.columns.url'))
                ->imageSize(40)
                ->alignCenter(),
            TextColumn::make('name')
                ->label(trans('curator::tables.columns.name'))
                ->searchable()
                ->sortable(),
            TextColumn::make('ext')
                ->label(trans('curator::tables.columns.ext'))
                ->sortable(),
            TextColumn::make('size')
                ->label(trans('curator::tables.columns.size'))
                ->formatStateUsing(fn ($record): string => Curator::sizeForHumans($record->size))
                ->sortable(),
            TextColumn::make('dimensions')
                ->label(trans('curator::tables.columns.dimensions'))
                ->getStateUsing(fn ($record): ?string => $record->width ? $record->width.'x'.$record->height : null),
            TextColumn::make('disk')
                ->label(trans('curator::tables.columns.disk'))
                ->toggledHiddenByDefault()
                ->toggleable()
                ->sortable(),
            TextColumn::make('directory')
                ->label(trans('curator::tables.columns.directory'))
                ->toggledHiddenByDefault()
                ->toggleable()
                ->sortable(),
            TextColumn::make('created_at')
                ->label(trans('curator::tables.columns.created_at'))
                ->date('Y-m-d')
                ->sortable(),
        ];
    }

    /** @throws Exception */
    public static function getDefaultGridTableColumns(): array
    {
        return [
            View::make('curator::components.tables.grid-column'),
            TextColumn::make('name')
                ->label(trans('curator::tables.columns.name'))
                ->extraAttributes(['style' => 'display: none;'])
                ->searchable()
                ->sortable(),
            TextColumn::make('ext')
                ->label(trans('curator::tables.columns.ext'))
                ->extraAttributes(['style' => 'display: none;'])
                ->sortable(),
            TextColumn::make('directory')
                ->label(trans('curator::tables.columns.directory'))
                ->extraAttributes(['style' => 'display: none;'])
                ->sortable(),
            TextColumn::make('created_at')
                ->label(trans('curator::tables.columns.created_at'))
                ->extraAttributes(['style' => 'display: none;'])
                ->sortable(),
        ];
    }
}

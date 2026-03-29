<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources;

use App\Filament\Resources\ScrapeSources\Pages\CreateScrapeSource;
use App\Filament\Resources\ScrapeSources\Pages\EditScrapeSource;
use App\Filament\Resources\ScrapeSources\Pages\ListScrapeSources;
use App\Filament\Resources\ScrapeSources\Schemas\ScrapeSourceForm;
use App\Filament\Resources\ScrapeSources\Tables\ScrapeSourcesTable;
use App\Filament\Concerns\HasCachedNavigationBadge;
use App\Models\ScrapeSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ScrapeSourceResource extends Resource
{
    use HasCachedNavigationBadge;
    protected static ?string $model = ScrapeSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static \UnitEnum|string|null $navigationGroup = 'Thu thập dữ liệu';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'Nguồn thu thập';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Nguồn thu thập';
    }

    public static function form(Schema $schema): Schema
    {
        return ScrapeSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScrapeSourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScrapeSources::route('/'),
            'create' => CreateScrapeSource::route('/create'),
            'edit' => EditScrapeSource::route('/{record}/edit'),
        ];
    }

    /**
     * Remove SoftDeletingScope so TrashedFilter can show deleted records.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getCachedCount();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }
}

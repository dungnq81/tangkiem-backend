<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories;

use App\Filament\Resources\Stories\Pages\CreateStory;
use App\Filament\Resources\Stories\Pages\EditStory;
use App\Filament\Resources\Stories\Pages\ListStories;
use App\Filament\Resources\Stories\Schemas\StoryForm;
use App\Filament\Resources\Stories\Tables\StoriesTable;
use App\Filament\Concerns\HasCachedNavigationBadge;
use App\Models\Story;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StoryResource extends Resource
{
    use HasCachedNavigationBadge;
    protected static ?string $model = Story::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'title';

    protected static \UnitEnum|string|null $navigationGroup = 'Nội dung';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'Truyện';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Truyện';
    }

    public static function form(Schema $schema): Schema
    {
        return StoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStories::route('/'),
            'create' => CreateStory::route('/create'),
            'edit' => EditStory::route('/{record}/edit'),
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs;

use App\Filament\Resources\ScrapeJobs\Pages\CreateScrapeJob;
use App\Filament\Resources\ScrapeJobs\Pages\EditScrapeJob;
use App\Filament\Resources\ScrapeJobs\Pages\ListScrapeJobs;
use App\Filament\Resources\ScrapeJobs\Pages\ViewScrapeJob;
use App\Filament\Resources\ScrapeJobs\RelationManagers\ScrapeItemsRelationManager;
use App\Filament\Resources\ScrapeJobs\Schemas\ScrapeJobForm;
use App\Filament\Resources\ScrapeJobs\Tables\ScrapeJobsTable;
use App\Filament\Resources\ScrapeJobs\Widgets\ChapterDetailPreviewWidget;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeCompletionWidget;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeJobProgressWidget;
use App\Filament\Concerns\HasCachedNavigationBadge;
use App\Models\ScrapeJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScrapeJobResource extends Resource
{
    use HasCachedNavigationBadge;
    protected static ?string $model = ScrapeJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static \UnitEnum|string|null $navigationGroup = 'Thu thập dữ liệu';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'Tác vụ thu thập';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tác vụ thu thập';
    }

    public static function form(Schema $schema): Schema
    {
        return ScrapeJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScrapeJobsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ScrapeItemsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            ScrapeJobProgressWidget::class,
            ScrapeCompletionWidget::class,
            ChapterDetailPreviewWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScrapeJobs::route('/'),
            'create' => CreateScrapeJob::route('/create'),
            'edit' => EditScrapeJob::route('/{record}/edit'),
            'view' => ViewScrapeJob::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getCachedCount();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $needsAttention = static::getModel()::whereIn('status', ['scraping', 'scraped'])->exists();

        return $needsAttention ? 'danger' : 'gray';
    }
}

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
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeCompletionWidget;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeJobProgressWidget;
use App\Models\ScrapeJob;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ScrapeJobResource extends Resource
{
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

    /**
     * Dynamic sub-navigation items per entity type from config.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $parentItems = parent::getNavigationItems();

        $entityTypes = config('scrape.entity_types', []);
        $baseUrl = static::getUrl('index');
        $parentLabel = static::getNavigationLabel();

        // Single cached query: counts per entity_type
        $counts = Cache::remember('nav_badge:ScrapeJob:entity_counts', 300, function () {
            return ScrapeJob::query()
                ->selectRaw('entity_type, COUNT(*) as total')
                ->groupBy('entity_type')
                ->pluck('total', 'entity_type')
                ->toArray();
        });

        $childItems = [];
        $childSort = 0;

        foreach ($entityTypes as $key => $config) {
            $label = $config['icon'] . ' ' . $config['label'];
            $filterUrl = $baseUrl . '?' . http_build_query([
                'filters' => [
                    'entity_type' => ['value' => $key],
                ],
            ]);

            $count = $counts[$key] ?? 0;

            $childItems[] = NavigationItem::make($label)
                ->group(static::getNavigationGroup())
                ->parentItem($parentLabel)
                ->icon($config['nav_icon'])
                ->sort(++$childSort)
                ->badge($count > 0 ? (string) $count : null, color: $config['color'])
                ->isActiveWhen(function () use ($key): bool {
                    // Check URL filter first (on list page)
                    $urlFilter = request()->query('filters');
                    if (is_array($urlFilter) && ($urlFilter['entity_type']['value'] ?? null) === $key) {
                        return true;
                    }

                    // Fallback: check session context (on create/edit/view pages)
                    return session(ListScrapeJobs::SESSION_ENTITY_TYPE) === $key
                        && ! request()->has('filters');
                })
                ->url($filterUrl);
        }

        return [
            ...$parentItems,
            ...$childItems,
        ];
    }
}

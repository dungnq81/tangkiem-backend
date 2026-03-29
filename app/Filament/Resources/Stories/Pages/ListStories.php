<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\Stories\StoryResource;
use App\Models\Category;
use App\Models\Story;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ListStories extends ListRecords
{
    protected static string $resource = StoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::emptyTrashAction(Story::class, 'truyện'),
            CreateAction::make(),
        ];
    }

    // ─── Category Tabs ───────────────────────────────────────────────

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Tất cả')
                ->icon(Heroicon::OutlinedRectangleStack),
        ];

        // Top 4 categories by story count — cached 5 minutes
        $topCategories = Cache::remember('story_tabs:top_categories', 300, fn () =>
            Category::query()
                ->withCount('stories')
                ->orderByDesc('stories_count')
                ->limit(4)
                ->get(['id', 'name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'count' => $c->stories_count,
                ])
                ->all()
        );

        foreach ($topCategories as $cat) {
            $tabs["category_{$cat['id']}"] = Tab::make($cat['name'])
                ->icon(Heroicon::OutlinedTag)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'categories',
                    fn (Builder $q) => $q->where('categories.id', $cat['id'])
                ))
                ->badge($cat['count']);
        }

        return $tabs;
    }

    // ─── Dynamic Subheading ──────────────────────────────────────────

    public function getSubheading(): ?string
    {
        $activeTab = $this->activeTab;

        if ($activeTab && str_starts_with($activeTab, 'category_')) {
            $categoryId = (int) str_replace('category_', '', $activeTab);
            // Reuse cached tab data instead of extra DB query
            $topCategories = Cache::get('story_tabs:top_categories', []);
            $match = collect($topCategories)->firstWhere('id', $categoryId);

            if ($match) {
                return "📂 Đang xem: {$match['name']}";
            }
        }

        return null;
    }
}

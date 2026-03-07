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

        // Top 3 categories by story count
        $topCategories = Category::query()
            ->withCount('stories')
            ->orderByDesc('stories_count')
            ->limit(3)
            ->get(['id', 'name']);

        foreach ($topCategories as $category) {
            $tabs["category_{$category->id}"] = Tab::make($category->name)
                ->icon(Heroicon::OutlinedTag)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'categories',
                    fn (Builder $q) => $q->where('categories.id', $category->id)
                ))
                ->badge($category->stories_count);
        }

        return $tabs;
    }

    // ─── Dynamic Subheading ──────────────────────────────────────────

    public function getSubheading(): ?string
    {
        $activeTab = $this->activeTab;

        if ($activeTab && str_starts_with($activeTab, 'category_')) {
            $categoryId = (int) str_replace('category_', '', $activeTab);
            $category = Category::find($categoryId);

            if ($category) {
                return "📂 Đang xem: {$category->name}";
            }
        }

        // Check if story filter is active via URL params
        $storyFilter = request()->input('tableFilters.categories.values');
        if (! empty($storyFilter)) {
            $names = Category::whereIn('id', $storyFilter)->pluck('name')->implode(', ');

            if ($names) {
                return "📂 Đang xem: {$names}";
            }
        }

        return null;
    }
}

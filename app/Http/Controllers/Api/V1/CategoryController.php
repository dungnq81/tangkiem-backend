<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryCollection;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\StoryCollection;
use App\Models\Category;
use App\Models\Story;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List all active categories.
     *
     * GET /v1/categories
     */
    public function index(Request $request): CategoryCollection
    {
        $query = Category::query()
            ->active()
            ->ordered()
            ->withCount(['stories' => fn ($q) => $q->where('stories.is_published', true)]);

        // Optionally include children for tree structure
        if ($request->boolean('tree')) {
            $query->root()->with([
                'children' => fn ($q) => $q->active()
                    ->ordered()
                    ->withCount(['stories' => fn ($sq) => $sq->where('stories.is_published', true)]),
            ]);
        }

        // Optional: filter featured
        if ($request->boolean('featured')) {
            $query->featured();
        }

        // Optional: filter menu-visible
        if ($request->boolean('menu')) {
            $query->inMenu();
        }

        return new CategoryCollection($query->get());
    }

    /**
     * Get category detail with stories.
     *
     * GET /v1/categories/{slug}
     */
    public function show(Request $request, string $slug): CategoryResource
    {
        $category = Category::query()
            ->active()
            ->where('slug', $slug)
            ->withCount(['stories' => fn ($q) => $q->where('stories.is_published', true)])
            ->firstOrFail();

        // Load paginated stories for this category
        $perPage = min((int) $request->get('per_page', 25), 100);

        $stories = Story::query()
            ->published()
            ->with(['author', 'primaryCategory', 'coverImage'])
            ->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
            ->latest('last_chapter_at')
            ->paginate($perPage);

        // Attach stories to the category resource
        return (new CategoryResource($category))
            ->additional([
                'stories' => new StoryCollection($stories),
            ]);
    }
}

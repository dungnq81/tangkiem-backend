<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoryCollection;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    /**
     * List published stories with pagination.
     */
    public function index(Request $request): StoryCollection
    {
        $query = Story::query()
            ->with(['author', 'primaryCategory', 'coverImage'])
            ->published()
            ->latest('updated_at');

        // Filters
        if ($request->has('category')) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $request->category));
        }

        if ($request->has('author')) {
            $query->whereHas('author', fn ($q) => $q->where('slug', $request->author));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('origin')) {
            $query->where('origin', $request->origin);
        }

        // Sort
        $sortField = $request->get('sort', 'updated_at');
        $sortDir = $request->get('order', 'desc');
        $allowedSorts = ['updated_at', 'created_at', 'title', 'view_count', 'rating_avg'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->get('per_page', 24), 100);

        return new StoryCollection($query->paginate($perPage));
    }

    /**
     * Get story details by slug.
     */
    public function show(string $slug): StoryResource
    {
        $story = Story::query()
            ->with([
                'author',
                'primaryCategory',
                'categories',
                'tags',
                'coverImage',
                'chapters' => fn ($q) => $q->published()->ordered()->limit(20),
            ])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return new StoryResource($story);
    }
}

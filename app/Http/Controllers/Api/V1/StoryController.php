<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Http\Controllers\Api\V1\Concerns\ExtractsSiteContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\RatingResource;
use App\Http\Resources\StoryCollection;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Services\Analytics\Interaction\BookmarkService;
use App\Services\Analytics\Interaction\HistoryService;
use App\Services\Analytics\Interaction\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    use ExtractsSiteContext;

    public function __construct(
        private readonly BookmarkService $bookmarks,
        private readonly RatingService $ratings,
        private readonly HistoryService $history,
    ) {}

    /**
     * List published stories with pagination.
     *
     * GET /v1/stories
     */
    public function index(Request $request): StoryCollection|JsonResponse
    {
        // Validate filter values against enums
        if ($request->has('status') && !StoryStatus::tryFrom($request->status)) {
            return response()->json([
                'success' => false,
                'message' => 'Giá trị status không hợp lệ.',
                'errors'  => [
                    'status' => ['Cho phép: ' . implode(', ', array_column(StoryStatus::cases(), 'value'))],
                ],
            ], 422);
        }

        if ($request->has('origin') && !StoryOrigin::tryFrom($request->origin)) {
            return response()->json([
                'success' => false,
                'message' => 'Giá trị origin không hợp lệ.',
                'errors'  => [
                    'origin' => ['Cho phép: ' . implode(', ', array_column(StoryOrigin::cases(), 'value'))],
                ],
            ], 422);
        }

        $query = Story::query()
            ->with(['author', 'primaryCategory', 'coverImage'])
            ->published();

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

        // Sort — default to last_chapter_at (= "recently updated" means new chapters)
        // updated_at is unreliable because AI jobs, admin edits, etc. touch it
        $sortField = $request->input('sort', 'last_chapter_at');
        $sortDir = $request->input('order', 'desc');
        $allowedSorts = ['updated_at', 'created_at', 'title', 'view_count', 'rating_avg', 'last_chapter_at'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        // Secondary sort for deterministic ordering when timestamps are identical
        $query->orderBy('id', 'desc');

        $perPage = min((int) $request->input('per_page', 24), 100);

        return new StoryCollection($query->paginate($perPage));
    }

    /**
     * Get story details by slug.
     *
     * GET /v1/stories/{slug}
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

    /**
     * Get story reviews/ratings (public, paginated).
     * Shows all ratings across all sites (global view for public display).
     *
     * GET /v1/stories/{id}/reviews
     */
    public function reviews(Request $request, int $id): JsonResponse
    {
        $story = Story::query()->published()->findOrFail($id);
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Reviews are global — shown across all sites
        $ratings = $this->ratings->reviews($story->id, siteId: null, perPage: $perPage);

        return response()->json([
            'success' => true,
            'data'    => RatingResource::collection($ratings),
            'meta'    => [
                'total'          => $ratings->total(),
                'per_page'       => $ratings->perPage(),
                'current_page'   => $ratings->currentPage(),
                'last_page'      => $ratings->lastPage(),
                'average_rating' => round((float) $story->rating, 1),
                'rating_count'   => $story->rating_count ?? $ratings->total(),
            ],
        ]);
    }

    /**
     * Get user's interaction status with a story (bookmark, rating, reading progress).
     * Lightweight endpoint for FE to check user state on story detail page.
     *
     * GET /v1/stories/{id}/user-status
     * Requires auth:sanctum
     */
    public function userStatus(Request $request, int $id): JsonResponse
    {
        $story = Story::query()->published()->findOrFail($id);
        $user = $request->user();
        $siteId = $this->getSiteId($request);

        $userRating = $this->ratings->getUserRating($user, $story->id, $siteId);
        $progress = $this->history->getProgress($user, $story->id, $siteId);

        // Check bookmark via direct query (lightweight, no loading full model)
        $isBookmarked = $user->bookmarks()
            ->where('story_id', $story->id)
            ->when($siteId !== null, fn ($q) => $q->where('api_domain_id', $siteId))
            ->when($siteId === null, fn ($q) => $q->whereNull('api_domain_id'))
            ->exists();

        return response()->json([
            'success' => true,
            'data'    => [
                'story_id'      => $story->id,
                'is_bookmarked' => $isBookmarked,
                'rating'        => $userRating ? [
                    'id'     => $userRating->id,
                    'rating' => $userRating->rating,
                    'review' => $userRating->review,
                ] : null,
                'reading_progress' => $progress ? [
                    'chapter_id'     => $progress->chapter_id,
                    'chapter_number' => $progress->chapter?->chapter_number,
                    'progress'       => $progress->progress,
                    'read_at'        => $progress->read_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookmarkResource;
use App\Http\Resources\RatingResource;
use App\Http\Resources\ReadingHistoryResource;
use App\Http\Resources\UserResource;
use App\Models\Bookmark;
use App\Models\Chapter;
use App\Models\Rating;
use App\Models\ReadingHistory;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    // Profile
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get current authenticated user profile.
     *
     * GET /v1/user
     */
    public function profile(Request $request): UserResource
    {
        $user = $request->user();

        $user->loadCount(['bookmarks', 'readingHistory']);

        return new UserResource($user);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bookmarks
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get user's bookmarked stories (paginated).
     *
     * GET /v1/user/bookmarks
     */
    public function bookmarks(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 25), 100);

        $bookmarks = $user->bookmarks()
            ->with([
                'story' => fn ($q) => $q->published(),
                'story.author',
                'story.primaryCategory',
                'story.coverImage',
            ])
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => BookmarkResource::collection($bookmarks),
            'meta' => [
                'total' => $bookmarks->total(),
                'per_page' => $bookmarks->perPage(),
                'current_page' => $bookmarks->currentPage(),
                'last_page' => $bookmarks->lastPage(),
            ],
        ]);
    }

    /**
     * Add a story to bookmarks.
     *
     * POST /v1/stories/{id}/bookmark
     */
    public function addBookmark(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Verify story exists and is published
        $story = Story::query()->published()->findOrFail($id);

        // Use updateOrCreate to prevent duplicates
        $bookmark = Bookmark::updateOrCreate(
            [
                'user_id' => $user->id,
                'story_id' => $story->id,
            ]
        );

        $wasRecentlyCreated = $bookmark->wasRecentlyCreated;

        return response()->json([
            'success' => true,
            'message' => $wasRecentlyCreated
                ? 'Đã thêm vào danh sách yêu thích'
                : 'Truyện đã có trong danh sách yêu thích',
            'data' => [
                'bookmarked' => true,
                'story_id' => $story->id,
            ],
        ], $wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a story from bookmarks.
     *
     * DELETE /v1/stories/{id}/bookmark
     */
    public function removeBookmark(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deleted = Bookmark::where('user_id', $user->id)
            ->where('story_id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Truyện không có trong danh sách yêu thích',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa khỏi danh sách yêu thích',
            'data' => [
                'bookmarked' => false,
                'story_id' => $id,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reading History
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get user's reading history (paginated).
     *
     * GET /v1/user/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 25), 100);

        $history = $user->readingHistory()
            ->with([
                'story' => fn ($q) => $q->published(),
                'story.author',
                'story.primaryCategory',
                'story.coverImage',
                'chapter:id,chapter_number,title,slug',
            ])
            ->latest('read_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ReadingHistoryResource::collection($history),
            'meta' => [
                'total' => $history->total(),
                'per_page' => $history->perPage(),
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
            ],
        ]);
    }

    /**
     * Save/update reading progress.
     *
     * POST /v1/user/history
     *
     * Body: { story_id, chapter_id, progress? (0-100) }
     */
    public function updateHistory(Request $request): JsonResponse
    {
        $request->validate([
            'story_id' => 'required|integer',
            'chapter_id' => 'required|integer',
            'progress' => 'sometimes|integer|min:0|max:100',
        ]);

        $user = $request->user();

        // Verify story & chapter exist
        $story = Story::query()->published()->findOrFail($request->integer('story_id'));
        $chapter = Chapter::where('story_id', $story->id)
            ->where('id', $request->integer('chapter_id'))
            ->firstOrFail();

        // Upsert: one history entry per story per user
        $history = ReadingHistory::updateOrCreate(
            [
                'user_id' => $user->id,
                'story_id' => $story->id,
            ],
            [
                'chapter_id' => $chapter->id,
                'progress' => $request->integer('progress', 0),
                'read_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật tiến độ đọc',
            'data' => [
                'story_id' => $story->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'progress' => $history->progress,
                'read_at' => $history->read_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a single history entry.
     *
     * DELETE /v1/user/history/{id}
     */
    public function deleteHistory(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deleted = ReadingHistory::where('user_id', $user->id)
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Mục lịch sử không tìm thấy',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa mục lịch sử',
        ]);
    }

    /**
     * Clear all reading history.
     *
     * DELETE /v1/user/history
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = ReadingHistory::where('user_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => "Đã xóa {$count} mục lịch sử",
            'data' => [
                'deleted_count' => $count,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratings
    // ═══════════════════════════════════════════════════════════════

    /**
     * Rate a story (create or update rating).
     *
     * POST /v1/stories/{id}/rate
     *
     * Body: { rating: 1-5, review?: "string" }
     */
    public function rate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $story = Story::query()->published()->findOrFail($id);

        $rating = Rating::updateOrCreate(
            [
                'user_id' => $user->id,
                'story_id' => $story->id,
            ],
            [
                'rating' => $request->integer('rating'),
                'review' => $request->string('review')->toString() ?: null,
            ]
        );

        $wasRecentlyCreated = $rating->wasRecentlyCreated;

        return response()->json([
            'success' => true,
            'message' => $wasRecentlyCreated
                ? 'Đã đánh giá truyện'
                : 'Đã cập nhật đánh giá',
            'data' => new RatingResource($rating),
        ], $wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove rating.
     *
     * DELETE /v1/stories/{id}/rate
     */
    public function removeRating(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deleted = Rating::where('user_id', $user->id)
            ->where('story_id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đánh giá truyện này',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa đánh giá',
            'data' => [
                'rated' => false,
                'story_id' => $id,
            ],
        ]);
    }

    /**
     * Get story reviews/ratings (public, paginated).
     *
     * GET /v1/stories/{id}/reviews
     */
    public function reviews(Request $request, int $id): JsonResponse
    {
        $story = Story::query()->published()->findOrFail($id);
        $perPage = min((int) $request->get('per_page', 25), 100);

        $ratings = Rating::where('story_id', $story->id)
            ->with('user:id,name')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => RatingResource::collection($ratings),
            'meta' => [
                'total' => $ratings->total(),
                'per_page' => $ratings->perPage(),
                'current_page' => $ratings->currentPage(),
                'last_page' => $ratings->lastPage(),
                'average_rating' => round((float) $story->rating, 1),
                'rating_count' => $story->rating_count ?? $ratings->total(),
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ExtractsSiteContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookmarkResource;
use App\Http\Resources\RatingResource;
use App\Http\Resources\ReadingHistoryResource;
use App\Http\Resources\UserResource;
use App\Models\Chapter;
use App\Models\Story;
use App\Services\Analytics\Interaction\BookmarkService;
use App\Services\Analytics\Interaction\HistoryService;
use App\Services\Analytics\Interaction\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ExtractsSiteContext;

    public function __construct(
        private readonly BookmarkService $bookmarks,
        private readonly RatingService $ratings,
        private readonly HistoryService $history,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // Profile
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get current authenticated user profile.
     * Counts are scoped to the requesting site.
     *
     * GET /v1/user
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);

        // Single query for all 3 site-scoped counts (instead of 3 separate COUNT queries)
        $bookmarkTable = (new \App\Models\Bookmark())->getTable();
        $historyTable = (new \App\Models\ReadingHistory())->getTable();
        $ratingTable = (new \App\Models\Rating())->getTable();

        if ($siteId !== null) {
            $counts = \Illuminate\Support\Facades\DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM `{$bookmarkTable}` WHERE user_id = ? AND api_domain_id = ?) as bookmark_count,
                    (SELECT COUNT(*) FROM `{$historyTable}` WHERE user_id = ? AND api_domain_id = ?) as history_count,
                    (SELECT COUNT(*) FROM `{$ratingTable}` WHERE user_id = ? AND api_domain_id = ?) as rating_count
            ", [$user->id, $siteId, $user->id, $siteId, $user->id, $siteId]);
        } else {
            $counts = \Illuminate\Support\Facades\DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM `{$bookmarkTable}` WHERE user_id = ? AND api_domain_id IS NULL) as bookmark_count,
                    (SELECT COUNT(*) FROM `{$historyTable}` WHERE user_id = ? AND api_domain_id IS NULL) as history_count,
                    (SELECT COUNT(*) FROM `{$ratingTable}` WHERE user_id = ? AND api_domain_id IS NULL) as rating_count
            ", [$user->id, $user->id, $user->id]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                ...(new UserResource($user))->toArray($request),
                'bookmark_count' => (int) $counts->bookmark_count,
                'history_count'  => (int) $counts->history_count,
                'rating_count'   => (int) $counts->rating_count,
            ],
        ]);
    }

    /**
     * Update user profile.
     *
     * PATCH /v1/user
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|min:2|max:50',
        ]);

        $user = $request->user();

        if (!empty($validated)) {
            $user->update($validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật thông tin.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bookmarks (per-site)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get user's bookmarked stories (paginated, scoped to site).
     *
     * GET /v1/user/bookmarks
     */
    public function bookmarks(Request $request): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);
        $perPage = min((int) $request->input('per_page', 25), 100);

        $bookmarks = $this->bookmarks->list($user, $siteId, $perPage);

        return response()->json([
            'success' => true,
            'data'    => BookmarkResource::collection($bookmarks),
            'meta'    => [
                'total'        => $bookmarks->total(),
                'per_page'     => $bookmarks->perPage(),
                'current_page' => $bookmarks->currentPage(),
                'last_page'    => $bookmarks->lastPage(),
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
        $siteId = $this->getSiteId($request);
        $story = Story::query()->published()->findOrFail($id);

        [$bookmark, $wasCreated] = $this->bookmarks->add($user, $story, $siteId);

        return response()->json([
            'success' => true,
            'message' => $wasCreated
                ? 'Đã thêm vào danh sách yêu thích'
                : 'Truyện đã có trong danh sách yêu thích',
            'data' => [
                'bookmarked' => true,
                'story_id'   => $story->id,
            ],
        ], $wasCreated ? 201 : 200);
    }

    /**
     * Remove a story from bookmarks.
     *
     * DELETE /v1/stories/{id}/bookmark
     */
    public function removeBookmark(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);

        $deleted = $this->bookmarks->remove($user, $id, $siteId);

        if (!$deleted) {
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
                'story_id'   => $id,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reading History (per-site)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get user's reading history (paginated, scoped to site).
     *
     * GET /v1/user/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);
        $perPage = min((int) $request->input('per_page', 25), 100);

        $history = $this->history->list($user, $siteId, $perPage);

        return response()->json([
            'success' => true,
            'data'    => ReadingHistoryResource::collection($history),
            'meta'    => [
                'total'        => $history->total(),
                'per_page'     => $history->perPage(),
                'current_page' => $history->currentPage(),
                'last_page'    => $history->lastPage(),
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
            'story_id'   => 'required|integer',
            'chapter_id' => 'required|integer',
            'progress'   => 'sometimes|integer|min:0|max:100',
        ]);

        $user = $request->user();
        $siteId = $this->getSiteId($request);

        $story = Story::query()->published()->findOrFail($request->integer('story_id'));
        $chapter = Chapter::where('story_id', $story->id)
            ->where('id', $request->integer('chapter_id'))
            ->firstOrFail();

        $history = $this->history->upsert(
            $user,
            $story,
            $chapter,
            $request->integer('progress', 0),
            $siteId,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật tiến độ đọc',
            'data'    => [
                'story_id'       => $story->id,
                'chapter_id'     => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'progress'       => $history->progress,
                'read_at'        => $history->read_at->toIso8601String(),
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
        $siteId = $this->getSiteId($request);

        $deleted = $this->history->delete($user, $id, $siteId);

        if (!$deleted) {
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
     * Clear all reading history for this site.
     *
     * DELETE /v1/user/history
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);

        $count = $this->history->clearAll($user, $siteId);

        return response()->json([
            'success' => true,
            'message' => "Đã xóa {$count} mục lịch sử",
            'data'    => [
                'deleted_count' => $count,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratings (per-site)
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
        $siteId = $this->getSiteId($request);
        $story = Story::query()->published()->findOrFail($id);

        [$rating, $wasCreated] = $this->ratings->rate(
            $user,
            $story,
            $request->integer('rating'),
            $request->string('review')->toString() ?: null,
            $siteId,
        );

        return response()->json([
            'success' => true,
            'message' => $wasCreated
                ? 'Đã đánh giá truyện'
                : 'Đã cập nhật đánh giá',
            'data' => new RatingResource($rating),
        ], $wasCreated ? 201 : 200);
    }

    /**
     * Remove rating.
     *
     * DELETE /v1/stories/{id}/rate
     */
    public function removeRating(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $siteId = $this->getSiteId($request);

        $deleted = $this->ratings->remove($user, $id, $siteId);

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đánh giá truyện này',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa đánh giá',
            'data'    => [
                'rated'    => false,
                'story_id' => $id,
            ],
        ]);
    }
}

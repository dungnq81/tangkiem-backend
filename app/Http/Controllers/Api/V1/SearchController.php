<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoryCollection;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search stories by keyword.
     *
     * GET /v1/search?q=keyword&status=ongoing&category=tien-hiep
     */
    public function index(Request $request): StoryCollection|JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Từ khóa tìm kiếm phải có ít nhất 2 ký tự.',
            ], 422);
        }

        // Security: limit search term length to prevent expensive queries
        if (mb_strlen($query) > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Từ khóa tìm kiếm tối đa 100 ký tự.',
            ], 422);
        }

        // Escape SQL LIKE wildcards to prevent wildcard abuse
        $escapedQuery = $this->escapeLike($query);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $stories = Story::query()
            ->published()
            ->where(function ($q) use ($escapedQuery) {
                $q->where('title', 'LIKE', "%{$escapedQuery}%")
                    ->orWhere('alternative_titles', 'LIKE', "%{$escapedQuery}%")
                    ->orWhereHas('author', fn ($aq) => $aq->where('name', 'LIKE', "%{$escapedQuery}%"));
            })
            ->with(['author', 'primaryCategory', 'coverImage'])
            ->latest('last_chapter_at')
            ->paginate($perPage);

        return new StoryCollection($stories);
    }

    /**
     * Quick search (lightweight, for autocomplete).
     *
     * GET /v1/search/suggest?q=keyword
     * Returns max 10 results with minimal fields.
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $escapedQuery = $this->escapeLike($query);

        $stories = Story::query()
            ->published()
            ->where(function ($q) use ($escapedQuery) {
                $q->where('title', 'LIKE', "%{$escapedQuery}%")
                    ->orWhere('alternative_titles', 'LIKE', "%{$escapedQuery}%");
            })
            ->select('id', 'title', 'slug', 'primary_category_id')
            ->with('primaryCategory:id,name,slug')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stories->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'slug' => $s->slug,
                'category' => $s->primaryCategory?->name,
            ]),
        ]);
    }

    /**
     * Escape SQL LIKE wildcards (%, _) to prevent wildcard abuse.
     */
    private function escapeLike(string $value): string
    {
        // IMPORTANT: Backslash MUST be first — str_replace applies sequentially.
        // If % is replaced first (\%), the \ would be double-escaped (\\%).
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}

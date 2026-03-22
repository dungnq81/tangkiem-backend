<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChapterCollection;
use App\Http\Resources\ChapterContentResource;
use App\Models\Chapter;
use App\Models\Story;
use App\Services\Cache\ViewCountService;
use Illuminate\Http\Request;

class ChapterController extends Controller
{
    public function __construct(
        protected ViewCountService $viewCountService,
    ) {}

    /**
     * List published chapters for a story.
     *
     * GET /v1/stories/{slug}/chapters
     */
    public function index(Request $request, string $storySlug): ChapterCollection
    {
        $story = Story::query()
            ->published()
            ->where('slug', $storySlug)
            ->firstOrFail();

        $query = $story->chapters()
            ->published()
            ->ordered()
            ->select([
                'id',
                'story_id',
                'chapter_number',
                'sub_chapter',
                'volume_number',
                'title',
                'slug',
                'word_count',
                'view_count',
                'is_vip',
                'is_free_preview',
                'published_at',
            ]);

        // Filter by volume
        if ($request->has('volume')) {
            $query->byVolume((int) $request->volume);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return new ChapterCollection($query->paginate($perPage));
    }

    /**
     * Get chapter content by slug.
     *
     * GET /v1/stories/{slug}/chapters/{chapterSlug}
     */
    public function show(Request $request, string $storySlug, string $chapterSlug): ChapterContentResource
    {
        $story = Story::query()
            ->published()
            ->where('slug', $storySlug)
            ->firstOrFail();

        $chapter = Chapter::query()
            ->with(['content', 'prevChapter', 'nextChapter', 'story:id,title,slug'])
            ->published()
            ->where('story_id', $story->id)
            ->where('slug', $chapterSlug)
            ->firstOrFail();

        // Visitor identifier for dedup: user ID (authenticated) or IP (guest)
        $visitorId = $request->user()?->id
            ? 'u:' . $request->user()->id
            : 'ip:' . $request->ip();

        // Increment view count with dedup (same visitor + chapter = 1 view per 30 min)
        $this->viewCountService->incrementChapterView($chapter->id, $story->id, $visitorId);

        return new ChapterContentResource($chapter);
    }
}

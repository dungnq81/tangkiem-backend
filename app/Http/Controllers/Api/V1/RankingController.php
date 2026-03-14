<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoryCollection;
use App\Services\Cache\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function __construct(
        protected RankingService $rankingService,
    ) {}

    /**
     * Top stories today.
     *
     * GET /v1/rankings/daily
     */
    public function daily(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 50);
        $stories = $this->rankingService->getDailyTop($limit);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\StoryResource::collection($stories),
            'meta' => [
                'period' => 'daily',
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Top stories this week.
     *
     * GET /v1/rankings/weekly
     */
    public function weekly(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 50);
        $stories = $this->rankingService->getWeeklyTop($limit);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\StoryResource::collection($stories),
            'meta' => [
                'period' => 'weekly',
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Top stories this month.
     *
     * GET /v1/rankings/monthly
     */
    public function monthly(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 50);
        $stories = $this->rankingService->getMonthlyTop($limit);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\StoryResource::collection($stories),
            'meta' => [
                'period' => 'monthly',
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Top stories all-time.
     *
     * GET /v1/rankings/all-time
     */
    public function allTime(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 50);
        $stories = $this->rankingService->getAllTimeTop($limit);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\StoryResource::collection($stories),
            'meta' => [
                'period' => 'all-time',
                'limit' => $limit,
            ],
        ]);
    }
}

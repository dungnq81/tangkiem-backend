<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoryResource;
use App\Services\Cache\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    private const ALLOWED_PERIODS = ['daily', 'weekly', 'monthly', 'all-time'];

    public function __construct(
        protected RankingService $rankingService,
    ) {}

    /**
     * Top stories for a given period.
     *
     * GET /v1/rankings/{period}
     *
     * @param string $period One of: daily, weekly, monthly, all-time
     */
    public function show(Request $request, string $period): JsonResponse
    {
        if (!in_array($period, self::ALLOWED_PERIODS)) {
            return response()->json([
                'success' => false,
                'message' => 'Khoảng thời gian không hợp lệ.',
                'errors'  => [
                    'period' => ['Cho phép: ' . implode(', ', self::ALLOWED_PERIODS)],
                ],
            ], 422);
        }

        $limit = min((int) $request->input('limit', 20), 50);

        $stories = match ($period) {
            'daily'    => $this->rankingService->getDailyTop($limit),
            'weekly'   => $this->rankingService->getWeeklyTop($limit),
            'monthly'  => $this->rankingService->getMonthlyTop($limit),
            'all-time' => $this->rankingService->getAllTimeTop($limit),
        };

        return response()->json([
            'success' => true,
            'data'    => StoryResource::collection($stories),
            'meta'    => [
                'period' => $period,
                'limit'  => $limit,
            ],
        ]);
    }
}

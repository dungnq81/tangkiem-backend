<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChapterController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\StoryController;
use App\Http\Controllers\Api\V1\UserController;
use App\Services\WebCronService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All public routes require ValidateApiDomain middleware.
| Use 'api.domain:group' to specify required API group.
|
| Rate limiting:
|   - Public:        60 requests/minute
|   - Authenticated: 120 requests/minute
|   - Search:        30 requests/minute
|
*/

Route::prefix('v1')->group(function () {

    // ═══════════════════════════════════════════════════════════════
    // Stories API
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:stories')->group(function () {
        Route::get('stories', [StoryController::class, 'index'])
            ->name('api.v1.stories.index');
        Route::get('stories/{slug}', [StoryController::class, 'show'])
            ->name('api.v1.stories.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Chapters API
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:chapters')->group(function () {
        Route::get('stories/{slug}/chapters', [ChapterController::class, 'index'])
            ->name('api.v1.chapters.index');
        Route::get('stories/{slug}/chapters/{chapterSlug}', [ChapterController::class, 'show'])
            ->name('api.v1.chapters.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Rankings API
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:rankings')->group(function () {
        Route::get('rankings/daily', [RankingController::class, 'daily'])
            ->name('api.v1.rankings.daily');
        Route::get('rankings/weekly', [RankingController::class, 'weekly'])
            ->name('api.v1.rankings.weekly');
        Route::get('rankings/monthly', [RankingController::class, 'monthly'])
            ->name('api.v1.rankings.monthly');
        Route::get('rankings/all-time', [RankingController::class, 'allTime'])
            ->name('api.v1.rankings.allTime');
    });

    // ═══════════════════════════════════════════════════════════════
    // Categories API
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:categories')->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])
            ->name('api.v1.categories.index');
        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->name('api.v1.categories.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Search API (rate limited: 30/min)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:search', 'throttle:search'])->group(function () {
        Route::get('search', [SearchController::class, 'index'])
            ->name('api.v1.search.index');
        Route::get('search/suggest', [SearchController::class, 'suggest'])
            ->name('api.v1.search.suggest');
    });

    // ═══════════════════════════════════════════════════════════════
    // Story Reviews (Public — anyone can read reviews)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:stories')->group(function () {
        Route::get('stories/{id}/reviews', [UserController::class, 'reviews'])
            ->name('api.v1.stories.reviews')
            ->where('id', '[0-9]+');
    });

    // ═══════════════════════════════════════════════════════════════
    // User API (Domain-validated + Authenticated via Sanctum)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:user', 'auth:sanctum'])->group(function () {
        // Profile
        Route::get('user', [UserController::class, 'profile'])
            ->name('api.v1.user.profile');

        // Bookmarks
        Route::get('user/bookmarks', [UserController::class, 'bookmarks'])
            ->name('api.v1.user.bookmarks');
        Route::post('stories/{id}/bookmark', [UserController::class, 'addBookmark'])
            ->name('api.v1.user.addBookmark')
            ->where('id', '[0-9]+');
        Route::delete('stories/{id}/bookmark', [UserController::class, 'removeBookmark'])
            ->name('api.v1.user.removeBookmark')
            ->where('id', '[0-9]+');

        // Reading History
        Route::get('user/history', [UserController::class, 'history'])
            ->name('api.v1.user.history');
        Route::post('user/history', [UserController::class, 'updateHistory'])
            ->name('api.v1.user.updateHistory');
        Route::delete('user/history/{id}', [UserController::class, 'deleteHistory'])
            ->name('api.v1.user.deleteHistory')
            ->where('id', '[0-9]+');
        Route::delete('user/history', [UserController::class, 'clearHistory'])
            ->name('api.v1.user.clearHistory');

        // Ratings
        Route::post('stories/{id}/rate', [UserController::class, 'rate'])
            ->name('api.v1.user.rate')
            ->where('id', '[0-9]+');
        Route::delete('stories/{id}/rate', [UserController::class, 'removeRating'])
            ->name('api.v1.user.removeRating')
            ->where('id', '[0-9]+');
    });
});

/*
|--------------------------------------------------------------------------
| Web Cron — WordPress-style auto-scheduler
|--------------------------------------------------------------------------
|
| Two endpoints:
|   - /api/web-cron-ping:   Lightweight ping (JS heartbeat, every N seconds)
|   - /api/web-cron:        Heavy worker (runs scheduled tasks + queue)
|
| Protected by HMAC token. Managed via WebCronPage in admin panel.
| All logic lives in App\Services\WebCronService.
|
*/

// Lightweight ping — called by heartbeat JS.
Route::get('web-cron-ping', function (Request $request) {
    if (! WebCronService::validateToken((string) $request->query('token', ''))) {
        abort(403, 'Invalid token');
    }

    return response()->json(WebCronService::handlePing(), 200);
})->name('api.web-cron-ping');

// Heavy worker — runs all scheduled tasks (fire-and-forget).
Route::get('web-cron', function (Request $request) {
    if (! WebCronService::validateToken((string) $request->query('token', ''))) {
        abort(403, 'Invalid token');
    }

    // Ensure this process runs to completion even if the caller disconnects
    ignore_user_abort(true);
    set_time_limit(0);

    WebCronService::executeWorker('heartbeat');

    return response('OK', 200);
})->name('api.web-cron');


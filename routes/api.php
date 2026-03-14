<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChapterController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SitemapController;
use App\Http\Controllers\Api\V1\StoryController;
use App\Http\Controllers\Api\V1\UserController;
use App\Services\WebCron\WebCronManager;
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
| Response Cache TTLs:
|   - Stories/Categories/Rankings: 300s (5 min) — frequently updated
|   - Sitemaps: 3600s (1 hour) — rarely changes
|   - Search: 120s (2 min) — dynamic but repeated queries
|   - Chapter content: NOT cached (has view count side effect)
|   - User routes: NOT cached (user-specific)
|
*/

Route::prefix('v1')->group(function () {

    // ═══════════════════════════════════════════════════════════════
    // Auth API (Rate limited: 5/min login, 3/min register)
    // NOT cached — user-specific, POST-only
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:user')->prefix('auth')->group(function () {
        // Public auth routes (no sanctum required)
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])
                ->name('api.v1.auth.register');
            Route::post('login', [AuthController::class, 'login'])
                ->name('api.v1.auth.login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
                ->name('api.v1.auth.forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])
                ->name('api.v1.auth.reset-password');
        });

        // Authenticated auth routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])
                ->name('api.v1.auth.logout');
        });
    });

    // ═══════════════════════════════════════════════════════════════
    // Stories API — cache 5 minutes
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:stories', 'cacheResponse:300'])->group(function () {
        Route::get('stories', [StoryController::class, 'index'])
            ->name('api.v1.stories.index');
        Route::get('stories/{slug}', [StoryController::class, 'show'])
            ->name('api.v1.stories.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Chapters API
    // - Chapter list: cache 5 minutes
    // - Chapter content: NOT cached (has view count increment)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:chapters')->group(function () {
        Route::get('stories/{slug}/chapters', [ChapterController::class, 'index'])
            ->middleware('cacheResponse:300')
            ->name('api.v1.chapters.index');
        Route::get('stories/{slug}/chapters/{chapterSlug}', [ChapterController::class, 'show'])
            ->middleware('doNotCacheResponse')
            ->name('api.v1.chapters.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Rankings API — cache 5 minutes
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:rankings', 'cacheResponse:300'])->group(function () {
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
    // Categories API — cache 5 minutes (list), 30 minutes (detail)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware('api.domain:categories')->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])
            ->middleware('cacheResponse:300')
            ->name('api.v1.categories.index');
        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->middleware('cacheResponse:1800')
            ->name('api.v1.categories.show');
    });

    // ═══════════════════════════════════════════════════════════════
    // Search API — cache 2 minutes (rate limited: 30/min)
    // Short TTL because search queries are dynamic
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:search', 'throttle:search', 'cacheResponse:120'])->group(function () {
        Route::get('search', [SearchController::class, 'index'])
            ->name('api.v1.search.index');
        Route::get('search/suggest', [SearchController::class, 'suggest'])
            ->name('api.v1.search.suggest');
    });

    // ═══════════════════════════════════════════════════════════════
    // Sitemap API — cache 1 hour (rarely changes)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:stories', 'cacheResponse:3600'])->group(function () {
        Route::get('sitemap.xml', [SitemapController::class, 'index'])
            ->name('api.v1.sitemap.index');
        Route::get('sitemap-{name}.xml', [SitemapController::class, 'show'])
            ->name('api.v1.sitemap.show')
            ->where('name', '[a-z0-9\-]+');
    });

    // ═══════════════════════════════════════════════════════════════
    // Story Reviews — cache 5 minutes
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:stories', 'cacheResponse:300'])->group(function () {
        Route::get('stories/{id}/reviews', [UserController::class, 'reviews'])
            ->name('api.v1.stories.reviews')
            ->where('id', '[0-9]+');
    });

    // ═══════════════════════════════════════════════════════════════
    // User API — NOT cached (user-specific + authenticated)
    // ═══════════════════════════════════════════════════════════════
    Route::middleware(['api.domain:user', 'auth:sanctum', 'doNotCacheResponse'])->group(function () {
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

        // Notifications
        Route::get('user/notifications', [NotificationController::class, 'index'])
            ->name('api.v1.user.notifications');
        Route::get('user/notifications/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('api.v1.user.notifications.unreadCount');
        Route::patch('user/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
            ->name('api.v1.user.notifications.markAsRead');
        Route::post('user/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('api.v1.user.notifications.markAllAsRead');
        Route::delete('user/notifications/{id}', [NotificationController::class, 'destroy'])
            ->name('api.v1.user.notifications.destroy');
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
| All logic lives in App\Services\WebCron\WebCronManager.
|
*/

// Lightweight ping — called by heartbeat JS.
Route::get('web-cron-ping', function (Request $request) {
    if (! WebCronManager::validateToken((string) $request->query('token', ''))) {
        abort(403, 'Invalid token');
    }

    return response()->json(WebCronManager::handlePing(), 200);
})->name('api.web-cron-ping');

// Heavy worker — runs all scheduled tasks (fire-and-forget).
Route::get('web-cron', function (Request $request) {
    if (! WebCronManager::validateToken((string) $request->query('token', ''))) {
        abort(403, 'Invalid token');
    }

    // Ensure this process runs to completion even if the caller disconnects
    ignore_user_abort(true);
    set_time_limit(0);

    WebCronManager::executeWorker('heartbeat');

    return response('OK', 200);
})->name('api.web-cron');

// Direct scrape runner — fire-and-forget endpoint for manual scrape triggers.
// Runs RunScrapeJob::handle() DIRECTLY in its own PHP-FPM process.
// No Redis queue involved — simpler and guaranteed to execute.
Route::get('scrape-run/{jobId}', function (Request $request, int $jobId) {
    if (! WebCronManager::validateToken((string) $request->query('token', ''))) {
        abort(403, 'Invalid token');
    }

    $scrapeJob = \App\Models\ScrapeJob::find($jobId);
    if (! $scrapeJob) {
        abort(404, 'Job not found');
    }

    // Keep running after caller disconnects (fire-and-forget)
    ignore_user_abort(true);
    set_time_limit(3600);

    // Run the job directly — no queue, no worker, just execute
    $job = new \App\Jobs\RunScrapeJob($scrapeJob);
    $job->handle(app(\App\Services\Scraper\ScraperService::class));

    return response('OK', 200);
})->name('api.scrape-run');

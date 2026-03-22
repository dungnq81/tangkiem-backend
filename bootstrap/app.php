<?php

use App\Http\Middleware\CheckBannedApiUser;
use App\Http\Middleware\CheckBannedUser;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\TrackPageVisit;
use App\Http\Middleware\UserActivity;
use App\Http\Middleware\ValidateApiDomain;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Configure API rate limiters
            RateLimiter::for('api', function (Request $request) {
                return $request->user()
                    ? Limit::perMinute(120)->by($request->user()->id)
                    : Limit::perMinute(60)->by($request->ip());
            });

            RateLimiter::for('search', function (Request $request) {
                return $request->user()
                    ? Limit::perMinute(30)->by($request->user()->id)
                    : Limit::perMinute(30)->by($request->ip());
            });

            RateLimiter::for('auth', function (Request $request) {
                return Limit::perMinute(5)->by($request->ip());
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all proxies (FastPanel: Nginx → Apache → PHP-FPM)
        // Nginx terminates SSL and forwards via HTTP, so Laravel needs
        // to trust X-Forwarded-* headers to generate HTTPS URLs.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            CheckBannedUser::class,
            UserActivity::class,
        ]);

        $middleware->statefulApi();

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->api(append: [
            CheckBannedApiUser::class,
            UserActivity::class,
            TrackPageVisit::class,
        ]);

        $middleware->alias([
            'api.domain' => ValidateApiDomain::class,
            'cacheResponse' => \Spatie\ResponseCache\Middlewares\CacheResponse::class,
            'doNotCacheResponse' => \Spatie\ResponseCache\Middlewares\DoNotCacheResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Auth errors → 401 (must be before generic handler)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Chưa xác thực. Vui lòng đăng nhập.',
            ], 401);
        });

        // Validation errors → 422 (Laravel default is fine, but ensure consistent format)
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });

        // Generic errors → clean JSON for all API routes
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // Let web routes use default handling
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            $message = match (true) {
                $status === 404 => 'Không tìm thấy tài nguyên.',
                $status === 405 => 'Phương thức không được hỗ trợ.',
                $status === 429 => 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau.',
                $status === 500 => 'Đã xảy ra lỗi hệ thống.',
                $status === 503 => 'Hệ thống đang bảo trì.',
                default => $e->getMessage() ?: 'Đã xảy ra lỗi.',
            };

            $response = [
                'success' => false,
                'message' => $message,
            ];

            // Only show debug info in non-production
            if (app()->hasDebugModeEnabled() && $status === 500) {
                $response['debug'] = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }

            return response()->json($response, $status);
        });
    })->create();

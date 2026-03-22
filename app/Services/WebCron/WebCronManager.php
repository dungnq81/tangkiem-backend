<?php

declare(strict_types=1);

namespace App\Services\WebCron;

use App\Models\Setting;
use App\Models\WebCronLog;
use App\Services\WebCron\Tasks\AiScheduledTask;
use App\Services\WebCron\Tasks\DispatchScrapeTask;
use App\Services\WebCron\Tasks\MonthlyMaintenanceTask;
use App\Services\WebCron\Tasks\ProcessQueueTask;
use App\Services\WebCron\Tasks\PublishChaptersTask;
use App\Services\WebCron\Tasks\RecoverStaleScrapeTask;
use App\Services\WebCron\Tasks\RefreshRankingsTask;
use App\Services\WebCron\Contracts\CronTaskInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Web Cron Manager — orchestrates tasks and manages lifecycle.
 *
 * Architecture:
 * 1. Heartbeat JS pings /api/web-cron-ping every N seconds (configurable)
 * 2. Ping endpoint calls handlePing() → throttle + lock → fire worker
 * 3. Worker endpoint calls executeWorker() → runs all registered tasks
 * 4. Every run is logged to web_cron_logs table
 *
 * Tasks are registered in the TASKS constant below.
 *
 * To add a new task:
 * 1. Create a class implementing CronTaskInterface (extend AbstractTask)
 * 2. Add the class to the TASKS constant
 *
 * @see \App\Http\Middleware\WebCronMiddleware
 * @see resources/views/filament/admin/web-cron-heartbeat.blade.php
 */
class WebCronManager
{
    // ═══════════════════════════════════════════════════════════════
    // Cache keys
    // ═══════════════════════════════════════════════════════════════

    /** Throttle key: prevent multiple pings per interval. */
    public const CACHE_THROTTLE = 'web_cron:last_check';

    /** Lock key: prevent concurrent worker runs. */
    public const CACHE_LOCK = 'web_cron:running';

    /** Default lock TTL in seconds (safety net if worker crashes). */
    public const LOCK_TTL = 1800; // 30 minutes

    /**
     * Registered task classes, executed in this order.
     *
     * @var array<int, class-string<CronTaskInterface>>
     */
    protected const TASKS = [
        PublishChaptersTask::class,        // Every cycle
        DispatchScrapeTask::class,         // Every cycle
        RecoverStaleScrapeTask::class,     // Every 15 min
        RefreshRankingsTask::class,        // Every 30 min
        AiScheduledTask::class,            // Every cycle (self-throttled)
        ProcessQueueTask::class,           // Every cycle
        MonthlyMaintenanceTask::class,     // Every 30 days
    ];

    // ═══════════════════════════════════════════════════════════════
    // Settings helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Whether Web Cron is enabled in System Settings.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('system.web_cron_enabled', false);
    }

    /**
     * Get the configured ping interval in seconds.
     * Default: 60s. Range: 30–300s.
     */
    public static function getInterval(): int
    {
        $interval = (int) Setting::get('system.web_cron_interval', 60);

        return max(30, min(300, $interval));
    }

    /**
     * Whether background mode is enabled (keep running when tab is inactive).
     */
    public static function isBackgroundEnabled(): bool
    {
        return (bool) Setting::get('system.web_cron_background', false);
    }

    /**
     * Generate a secure HMAC token for endpoint authentication.
     */
    public static function generateToken(): string
    {
        return hash_hmac('sha256', 'web-cron', config('app.key'));
    }

    /**
     * Validate a token from request query string.
     */
    public static function validateToken(string $token): bool
    {
        return hash_equals(self::generateToken(), $token);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ping handler (lightweight — called by heartbeat JS)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Handle a ping request.
     *
     * @return array{status: string, reason?: string}
     */
    public static function handlePing(): array
    {
        if (! self::isEnabled()) {
            return ['status' => 'disabled', 'reason' => 'Web cron is turned off'];
        }

        $interval = self::getInterval();

        // Atomic throttle: only one ping per interval
        if (! Cache::add(self::CACHE_THROTTLE, true, $interval)) {
            return ['status' => 'throttled', 'reason' => 'Already checked within interval'];
        }

        // Prevent concurrent worker runs
        if (! Cache::add(self::CACHE_LOCK, true, self::LOCK_TTL)) {
            return ['status' => 'locked', 'reason' => 'Worker is already running'];
        }

        // Fire worker via internal HTTP call (fire-and-forget)
        try {
            $cronUrl = url('/api/web-cron');
            $token = self::generateToken();

            $http = \Illuminate\Support\Facades\Http::timeout(1)->connectTimeout(1);

            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $http->get($cronUrl, ['token' => $token]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // Expected: timeout after 1s while server continues processing
        } catch (\Throwable $e) {
            Cache::forget(self::CACHE_LOCK);

            Log::warning('WebCron: ping failed to fire worker', [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'reason' => $e->getMessage()];
        }

        return ['status' => 'fired'];
    }

    // ═══════════════════════════════════════════════════════════════
    // Worker executor (runs all registered tasks)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Execute all registered web cron tasks.
     *
     * Should be called with ignore_user_abort(true) + set_time_limit(0).
     *
     * @param string $trigger 'heartbeat', 'manual', or 'server_cron'
     */
    public static function executeWorker(string $trigger = 'heartbeat'): WebCronLog
    {
        $startTime = microtime(true);
        $memBefore = memory_get_usage(true);

        // Clean up stale "running" entries from previous crashes
        WebCronLog::markStaleAsTimedOut();

        // Create log entry
        $log = WebCronLog::create([
            'started_at' => now(),
            'status'     => 'running',
            'trigger'    => $trigger,
        ]);

        // Run all registered tasks
        $tasks = self::runAllTasks();

        // Finalize
        $hasFailure = collect($tasks)->contains(fn ($t) => $t['status'] === 'failed');
        $duration = (int) ((microtime(true) - $startTime) * 1000);
        $memDelta = max(0, memory_get_usage(true) - $memBefore);

        $log->update([
            'finished_at'    => now(),
            'duration_ms'    => $duration,
            'status'         => $hasFailure ? 'partial' : 'success',
            'tasks_summary'  => $tasks,
            'memory_peak_mb' => (int) ceil($memDelta / 1024 / 1024),
        ]);

        // Release lock so next run can start
        Cache::forget(self::CACHE_LOCK);

        return $log;
    }

    // ═══════════════════════════════════════════════════════════════
    // Status & Statistics (for admin UI)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get current web cron status for the admin dashboard.
     *
     * Optimized: uses 2 queries total (1 lastRun + 1 aggregate).
     *
     * @return array{
     *   enabled: bool,
     *   interval: int,
     *   is_running: bool,
     *   last_run: ?array{at: string, ago: string, duration_ms: int, status: string},
     *   stats_24h: array{total: int, success: int, failed: int, success_rate: float, avg_duration_ms: float},
     *   stats_7d: array{total: int, success: int, failed: int, success_rate: float}
     * }
     */
    public static function getStatus(): array
    {
        $lastRun = WebCronLog::lastRun();
        $isRunning = Cache::has(self::CACHE_LOCK);

        $now = now();
        $since24h = $now->copy()->subHours(24);
        $since7d = $now->copy()->subHours(168);

        $aggregate = WebCronLog::query()
            ->where('started_at', '>=', $since7d)
            ->whereIn('status', ['success', 'partial', 'failed', 'timed_out'])
            ->selectRaw('
                COUNT(*) as total_7d,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_7d,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_7d,
                SUM(CASE WHEN started_at >= ? THEN 1 ELSE 0 END) as total_24h,
                SUM(CASE WHEN started_at >= ? AND status = ? THEN 1 ELSE 0 END) as success_24h,
                SUM(CASE WHEN started_at >= ? AND status = ? THEN 1 ELSE 0 END) as failed_24h,
                AVG(CASE WHEN started_at >= ? THEN duration_ms END) as avg_duration_24h
            ', [
                'success', 'failed',
                $since24h, $since24h, 'success',
                $since24h, 'failed',
                $since24h,
            ])
            ->first();

        $total24h = (int) ($aggregate->total_24h ?? 0);
        $success24h = (int) ($aggregate->success_24h ?? 0);
        $failed24h = (int) ($aggregate->failed_24h ?? 0);
        $total7d = (int) ($aggregate->total_7d ?? 0);
        $success7d = (int) ($aggregate->success_7d ?? 0);
        $failed7d = (int) ($aggregate->failed_7d ?? 0);

        return [
            'enabled'    => self::isEnabled(),
            'interval'   => self::getInterval(),
            'is_running' => $isRunning,
            'last_run'   => $lastRun ? [
                'at'          => $lastRun->started_at->toIso8601String(),
                'ago'         => $lastRun->started_at->diffForHumans(),
                'duration_ms' => $lastRun->duration_ms,
                'status'      => $lastRun->status,
                'trigger'     => $lastRun->trigger,
            ] : null,
            'stats_24h' => [
                'total'           => $total24h,
                'success'         => $success24h,
                'failed'          => $failed24h,
                'success_rate'    => $total24h > 0 ? round(($success24h / $total24h) * 100, 1) : 0.0,
                'avg_duration_ms' => round((float) ($aggregate->avg_duration_24h ?? 0), 0),
            ],
            'stats_7d' => [
                'total'        => $total7d,
                'success'      => $success7d,
                'failed'       => $failed7d,
                'success_rate' => $total7d > 0 ? round(($success7d / $total7d) * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * Get recent execution logs for admin display.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WebCronLog>
     */
    public static function getRecentLogs(int $limit = 50)
    {
        return WebCronLog::query()
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Manually force-release the lock (for stuck situations).
     */
    public static function clearLock(): void
    {
        Cache::forget(self::CACHE_LOCK);
        Cache::forget(self::CACHE_THROTTLE);
    }

    /**
     * Manually trigger a cron run (from admin UI).
     * Bypasses throttle and lock checks.
     */
    public static function runManually(): WebCronLog
    {
        self::clearLock();
        Cache::put(self::CACHE_LOCK, true, self::LOCK_TTL);

        return self::executeWorker('manual');
    }

    // ═══════════════════════════════════════════════════════════════
    // Task Management
    // ═══════════════════════════════════════════════════════════════

    /**
     * Boot and run all registered tasks.
     *
     * @return array<int, array{task: string, status: string, duration_ms: int, output: ?string, error: ?string}>
     */
    protected static function runAllTasks(): array
    {
        $results = [];

        foreach (self::TASKS as $class) {
            $task = app($class);

            // Skip tasks that shouldn't run this cycle (throttled)
            if (! $task->shouldRun()) {
                continue;
            }

            $results[] = self::runSingleTask($task);
        }

        return $results;
    }

    /**
     * Run a single task with error handling and timing.
     *
     * @return array{task: string, status: string, duration_ms: int, output: ?string, error: ?string}
     */
    protected static function runSingleTask(CronTaskInterface $task): array
    {
        $start = microtime(true);

        try {
            $output = $task->execute();
            $duration = (int) ((microtime(true) - $start) * 1000);

            if ($output) {
                Log::debug("WebCron: {$task->name()}", ['output' => $output]);
            }

            return [
                'task'        => $task->name(),
                'status'      => 'success',
                'duration_ms' => $duration,
                'output'      => $output,
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);

            Log::error("WebCron: {$task->name()} failed", [
                'error' => $e->getMessage(),
            ]);

            return [
                'task'        => $task->name(),
                'status'      => 'failed',
                'duration_ms' => $duration,
                'output'      => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
}

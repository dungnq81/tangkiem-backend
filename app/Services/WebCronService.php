<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\WebCronLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Web Cron Service — manages the complete lifecycle of web-based cron.
 *
 * Architecture:
 * 1. Heartbeat JS pings /api/web-cron-ping every N seconds (configurable)
 * 2. Ping endpoint calls handlePing() → throttle + lock → fire worker
 * 3. Worker endpoint calls executeWorker() → runs all scheduled tasks
 * 4. Every run is logged to web_cron_logs table
 *
 * @see \App\Http\Middleware\WebCronMiddleware  Constants + token generation
 * @see resources/views/filament/admin/web-cron-heartbeat.blade.php
 */
class WebCronService
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
     * Returns an array: ['status' => string, 'reason' => ?string]
     *
     * Possible statuses:
     * - 'disabled' → Web cron is turned off
     * - 'throttled' → Already pinged within interval
     * - 'locked' → Worker is currently running
     * - 'fired' → Worker started successfully
     * - 'error' → Failed to fire worker
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
    // Worker executor (heavy — runs all scheduled tasks)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Execute all scheduled web cron tasks.
     *
     * Should be called with ignore_user_abort(true) + set_time_limit(0).
     *
     * @param string $trigger  'heartbeat', 'manual', or 'server_cron'
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

        $tasks = [];
        $hasFailure = false;

        // ─── Task 1: Dispatch scheduled scrape tasks ───
        $tasks[] = self::runTask('scrape:run-scheduled', function () {
            Artisan::call('scrape:run-scheduled');
            return trim(Artisan::output());
        });

        // ─── Task 2: Recover stale scrape jobs (every 15 min) ───
        if (Cache::add('web_cron:scrape_recover_stale', true, 900)) {
            $tasks[] = self::runTask('scrape:recover-stale', function () {
                Artisan::call('scrape:recover-stale');
                return trim(Artisan::output());
            });
        }

        // ─── Task 3: Refresh rankings cache (every 30 min) ───
        if (Cache::add('web_cron:rankings_refresh', true, 1800)) {
            $tasks[] = self::runTask('rankings:refresh', function () {
                Artisan::call('rankings:refresh');
                return trim(Artisan::output());
            });
        }

        // ─── Task 4: AI scheduled tasks (content + SEO) ───
        $tasks[] = self::runTask('ai:run-scheduled', function () {
            Artisan::call('ai:run-scheduled');
            $output = trim(Artisan::output());
            // Suppress "no tasks" output to reduce noise
            if ($output === 'No AI tasks were due or no stories to process.') {
                return null;
            }
            return $output;
        });

        // ─── Task 5: Process ALL queued jobs ───
        $tasks[] = self::runTask('queue:work', function () {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--timeout'         => 300, // 5 min max per job — prevents infinite hangs
                '--memory'          => 512,
                '--quiet'           => true,
            ]);
            return null;
        });

        // ─── Task 6: Monthly maintenance ───
        if (Cache::add('web_cron:monthly_maintenance', true, 60 * 60 * 24 * 30)) {
            $tasks[] = self::runTask('maintenance:logs-cleanup', function () {
                Artisan::call('logs:cleanup', ['--days' => 90]);
                return trim(Artisan::output());
            });

            $tasks[] = self::runTask('maintenance:scrape-cleanup', function () {
                Artisan::call('scrape:cleanup');
                return trim(Artisan::output());
            });

            // Clean up old web cron logs too
            $tasks[] = self::runTask('maintenance:cron-logs-cleanup', function () {
                $deleted = WebCronLog::cleanup(500);
                return $deleted > 0 ? "Deleted {$deleted} old log entries" : null;
            });
        }

        // ─── Finalize ───
        $hasFailure = collect($tasks)->contains(fn ($t) => $t['status'] === 'failed');
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        // Memory delta: actual memory used by this run (not total process peak)
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
     * Optimized: uses 2 queries total (1 lastRun + 1 aggregate)
     * instead of the naive 11-query approach.
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
        $lastRun = WebCronLog::lastRun(); // Query 1
        $isRunning = Cache::has(self::CACHE_LOCK);

        // Query 2: Single aggregate query for both 24h and 7d stats
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
        // Clear any existing locks
        self::clearLock();

        // Acquire lock for this manual run
        Cache::put(self::CACHE_LOCK, true, self::LOCK_TTL);

        return self::executeWorker('manual');
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Run a single task with error handling and timing.
     *
     * @return array{task: string, status: string, duration_ms: int, output: ?string, error: ?string}
     */
    private static function runTask(string $name, callable $fn): array
    {
        $start = microtime(true);

        try {
            $output = $fn();
            $duration = (int) ((microtime(true) - $start) * 1000);

            if ($output) {
                Log::debug("WebCron: {$name}", ['output' => $output]);
            }

            return [
                'task'        => $name,
                'status'      => 'success',
                'duration_ms' => $duration,
                'output'      => $output,
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);

            Log::error("WebCron: {$name} failed", [
                'error' => $e->getMessage(),
            ]);

            return [
                'task'        => $name,
                'status'      => 'failed',
                'duration_ms' => $duration,
                'output'      => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
}

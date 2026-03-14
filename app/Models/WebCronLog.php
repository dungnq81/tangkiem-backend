<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Execution log for the Web Cron system.
 *
 * Tracks every cron run (heartbeat, manual, server_cron) with task-level detail.
 *
 * @property int         $id
 * @property \Carbon\Carbon $started_at
 * @property ?\Carbon\Carbon $finished_at
 * @property ?int        $duration_ms
 * @property string      $status       running|success|partial|failed
 * @property string      $trigger      heartbeat|manual|server_cron
 * @property ?array      $tasks_summary
 * @property ?int        $memory_peak_mb
 * @property ?string     $error
 */
class WebCronLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'started_at',
        'finished_at',
        'duration_ms',
        'status',
        'trigger',
        'tasks_summary',
        'memory_peak_mb',
        'error',
    ];

    protected $casts = [
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
        'duration_ms'   => 'integer',
        'tasks_summary' => 'array',
        'memory_peak_mb' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    /**
     * Only completed runs (not currently running).
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['success', 'partial', 'failed', 'timed_out']);
    }

    /**
     * Only successful runs.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Runs within the last N hours.
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('started_at', '>=', now()->subHours($hours));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the last completed run.
     */
    public static function lastRun(): ?self
    {
        return static::completed()
            ->latest('started_at')
            ->first();
    }

    /**
     * Get success rate over the last N hours (0-100).
     */
    public static function successRate(int $hours = 24): float
    {
        $total = static::completed()->recent($hours)->count();

        if ($total === 0) {
            return 0.0;
        }

        $successful = static::successful()->recent($hours)->count();

        return round(($successful / $total) * 100, 1);
    }

    /**
     * Get average duration in milliseconds over the last N hours.
     */
    public static function averageDuration(int $hours = 24): float
    {
        return round(
            (float) (static::completed()->recent($hours)->avg('duration_ms') ?? 0),
            0
        );
    }

    /**
     * Mark stale "running" entries as timed out.
     *
     * If a worker crashes mid-execution, the log entry stays as 'running'
     * forever. This method auto-detects entries running for > LOCK_TTL
     * and marks them as 'timed_out' so they don't confuse the admin UI.
     */
    public static function markStaleAsTimedOut(): int
    {
        return static::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(30))
            ->update([
                'status'      => 'timed_out',
                'finished_at' => now(),
                'error'       => 'Worker timed out or crashed before completing.',
            ]);
    }

    /**
     * Clean up old logs, keeping the most recent N entries.
     */
    public static function cleanup(int $keepCount = 500): int
    {
        $threshold = static::query()
            ->orderByDesc('id')
            ->skip($keepCount)
            ->value('id');

        if (! $threshold) {
            return 0;
        }

        return static::query()
            ->where('id', '<=', $threshold)
            ->delete();
    }
}

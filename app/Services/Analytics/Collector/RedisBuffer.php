<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collector;

use App\Services\Analytics\Data\VisitData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Buffer — Manages the analytics visit buffer.
 *
 * Single responsibility: push visits to Redis, pop for flushing.
 * Follows the same Buffer pattern as ViewCountService.
 *
 * Performance:
 * - push() uses Lua script for atomic LLEN+RPUSH (1 RTT instead of 2)
 * - Config cached in constructor
 * - flush() batch_size cached in constructor
 */
class RedisBuffer
{
    private readonly string $key;

    private readonly int $maxSize;

    private readonly int $batchSize;

    /**
     * Lua script: atomic check-and-push.
     * Returns 1 if pushed, 0 if buffer full.
     */
    private const LUA_PUSH = <<<'LUA'
        if redis.call('LLEN', KEYS[1]) >= tonumber(ARGV[2]) then
            return 0
        end
        redis.call('RPUSH', KEYS[1], ARGV[1])
        return 1
    LUA;

    public function __construct()
    {
        $this->key = (string) config('analytics.buffer.key', 'analytics:visits');
        $this->maxSize = (int) config('analytics.buffer.max_size', 10000);
        $this->batchSize = (int) config('analytics.buffer.batch_size', 500);
    }

    /**
     * Push a visit to the buffer using atomic Lua script (1 RTT).
     *
     * Previous approach used LLEN + RPUSH (2 RTTs per request).
     * Lua script executes atomically on Redis server.
     */
    public function push(VisitData $visit): bool
    {
        $payload = json_encode($visit->toArray(), JSON_UNESCAPED_UNICODE);

        $result = Redis::eval(
            self::LUA_PUSH,
            1,             // Number of KEYS
            $this->key,    // KEYS[1]
            $payload,      // ARGV[1]
            $this->maxSize // ARGV[2]
        );

        if ($result === 0) {
            Log::warning("Analytics buffer full ({$this->maxSize}). Dropping visit.");

            return false;
        }

        return true;
    }

    /**
     * Pop a chunk of entries from the buffer (up to batchSize).
     *
     * Uses LPOP in a loop (atomic per item) — no data loss on crash.
     * Called by AnalyticsAggregator in a do-while until empty.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $entries = [];

        for ($i = 0; $i < $this->batchSize; $i++) {
            $raw = Redis::lpop($this->key);

            if ($raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * Get current buffer size.
     */
    public function size(): int
    {
        return (int) Redis::llen($this->key);
    }
}

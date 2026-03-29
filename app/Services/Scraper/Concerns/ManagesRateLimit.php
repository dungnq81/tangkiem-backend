<?php

declare(strict_types=1);

namespace App\Services\Scraper\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Adaptive rate limiting, DNS caching, and memory management.
 *
 * Extracted from ScraperService to reduce file size.
 */
trait ManagesRateLimit
{
    protected int $currentDelayMs = 0;

    protected int $consecutiveSuccess = 0;

    /** @var array<string, ?string> In-memory DNS cache for pool requests */
    protected array $dnsCache = [];

    /**
     * Initialize rate limiter with base delay.
     */
    public function initRateLimiter(int $baseDelayMs): void
    {
        $this->currentDelayMs = $baseDelayMs;
        $this->consecutiveSuccess = 0;
    }

    /**
     * Get current delay in milliseconds.
     */
    public function getCurrentDelayMs(): int
    {
        return $this->currentDelayMs;
    }

    /**
     * Get DNS cache reference (for pool requests in DetailFetcher).
     *
     * @return array<string, ?string>
     */
    public function &getDnsCache(): array
    {
        return $this->dnsCache;
    }

    /**
     * Adjust delay between batches based on server response.
     */
    public function adaptDelay(int $baseDelay, bool $wasRateLimited): void
    {
        if ($wasRateLimited) {
            $this->currentDelayMs = min(30000, $this->currentDelayMs * 2);
            $this->consecutiveSuccess = 0;
            Log::info('Rate limited — increasing delay', [
                'delay_ms' => $this->currentDelayMs,
            ]);
        } else {
            $this->consecutiveSuccess++;
            if ($this->consecutiveSuccess >= 10) {
                $this->currentDelayMs = max($baseDelay, (int) ($this->currentDelayMs * 0.9));
                $this->consecutiveSuccess = 0;
            }
        }
    }

    /**
     * Parse PHP's memory_limit into bytes.
     */
    public function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return 0;
        }

        $value = (int) $limit;
        $suffix = strtoupper(substr(trim($limit), -1));

        return match ($suffix) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };
    }
}

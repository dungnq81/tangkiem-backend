<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collector;

/**
 * BotDetector
 *
 * Lightweight bot detection using User-Agent pattern matching.
 * Bot visits are still tracked but flagged with is_bot = true.
 */
class BotDetector
{
    /**
     * Compiled regex pattern (built once, cached in memory).
     */
    private ?string $compiledPattern = null;

    /**
     * Check if the User-Agent belongs to a known bot/crawler.
     */
    public function isBot(?string $userAgent): bool
    {
        if (!$userAgent || $userAgent === '') {
            // Empty UA is suspicious — often headless browsers or simple bots
            return true;
        }

        return (bool) preg_match($this->getPattern(), $userAgent);
    }

    /**
     * Build and cache the regex pattern from config.
     */
    private function getPattern(): string
    {
        if ($this->compiledPattern !== null) {
            return $this->compiledPattern;
        }

        /** @var string[] $patterns */
        $patterns = config('analytics.bot_patterns', []);

        if (empty($patterns)) {
            $this->compiledPattern = '/(?!)/'; // Never matches
            return $this->compiledPattern;
        }

        // Escape special regex chars and join with alternation
        $escaped = array_map(
            fn (string $p) => preg_quote($p, '/'),
            $patterns
        );

        $this->compiledPattern = '/' . implode('|', $escaped) . '/i';

        return $this->compiledPattern;
    }
}

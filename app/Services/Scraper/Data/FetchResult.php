<?php

declare(strict_types=1);

namespace App\Services\Scraper\Data;

/**
 * DTO: Result of an HTML page fetch operation.
 *
 * Immutable value object carrying the HTML content along with
 * metadata about how it was fetched (timing, driver, CF bypass).
 */
final readonly class FetchResult
{
    public function __construct(
        public string $html,
        public string $url,
        public int $fetchTimeMs = 0,
        public ?string $driver = null,
        public bool $cfBypassed = false,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    /**
     * HTML content length in bytes (useful for metrics).
     */
    public function sizeBytes(): int
    {
        return strlen($this->html);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Scraper\Exceptions;

/**
 * Base exception for all scraping operations.
 * Extend this for specific error categories.
 */
class ScrapeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $url = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

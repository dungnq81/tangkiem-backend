<?php

declare(strict_types=1);

namespace App\Services\Scraper\Exceptions;

/**
 * Thrown when content extraction (CSS or AI) fails.
 */
class ExtractionException extends ScrapeException
{
    public function __construct(
        string $message,
        ?string $url = null,
        public readonly string $method = 'css',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $url, 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Scraper\Exceptions;

/**
 * Thrown when HTML fetching fails (timeout, HTTP error, connection issue).
 */
class FetchException extends ScrapeException
{
    public function __construct(
        string $message,
        ?string $url = null,
        public readonly int $httpStatus = 0,
        public readonly string $errorType = 'transient',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $url, $httpStatus, $previous);
    }

    public function isTransient(): bool
    {
        return $this->errorType === 'transient';
    }

    public function isPermanent(): bool
    {
        return $this->errorType === 'permanent';
    }
}

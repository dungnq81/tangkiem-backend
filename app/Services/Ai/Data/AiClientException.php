<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * AI Client Exception — Thrown on AI API errors.
 *
 * Carries provider/model context for debugging and retry decisions.
 */
class AiClientException extends \RuntimeException
{
    /**
     * @param  string          $message   Error message.
     * @param  string          $provider  Provider slug.
     * @param  string          $model     Model ID.
     * @param  int             $code      HTTP status code.
     * @param  \Throwable|null $previous  Previous exception.
     */
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly string $model = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Whether this is a rate-limit error (HTTP 429).
     */
    public function isRateLimited(): bool
    {
        return 429 === $this->getCode();
    }

    /**
     * Whether this is a quota/billing error.
     *
     * Checks HTTP 429 (rate limit), 402 (payment required), and
     * common quota-related keywords in the error message.
     */
    public function isQuotaExceeded(): bool
    {
        if (in_array($this->getCode(), [429, 402], true)) {
            return true;
        }

        $lower = strtolower($this->getMessage());

        return str_contains($lower, 'quota')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'billing')
            || str_contains($lower, 'insufficient');
    }

    /**
     * Get a user-friendly error summary for logging/admin.
     */
    public function getAdminMessage(): string
    {
        $prefix = "[AI: {$this->provider}]";

        if ($this->isRateLimited()) {
            return "{$prefix} Rate limit exceeded. Try again later or switch provider.";
        }

        if ($this->isQuotaExceeded()) {
            return "{$prefix} Quota or billing limit reached. Check your API plan.";
        }

        return "{$prefix} API error: {$this->getMessage()} (HTTP {$this->getCode()})";
    }
}

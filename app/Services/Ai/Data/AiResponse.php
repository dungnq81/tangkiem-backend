<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * AI Response — Standardized response from any AI provider.
 *
 * Immutable DTO returned by all AI client drivers.
 * Normalizes response data regardless of which provider/API format was used.
 */
readonly class AiResponse
{
    /**
     * @param  string  $content       The text content returned by the AI.
     * @param  string  $provider      Provider slug (e.g., 'gemini', 'openai').
     * @param  string  $model         Model ID used.
     * @param  int     $promptTokens  Input tokens used.
     * @param  int     $outputTokens  Output tokens generated.
     * @param  string  $finishReason  Reason the response ended (e.g., 'stop', 'length').
     */
    public function __construct(
        public string $content,
        public string $provider = '',
        public string $model = '',
        public int $promptTokens = 0,
        public int $outputTokens = 0,
        public string $finishReason = 'stop',
    ) {}

    /**
     * Get total tokens used (input + output).
     */
    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->outputTokens;
    }

    /**
     * Check if the response has non-empty content.
     */
    public function hasContent(): bool
    {
        return '' !== trim($this->content);
    }

    /**
     * Try to decode the content as JSON.
     *
     * Strips markdown code fences if present (```json ... ```).
     *
     * @return array<string, mixed>|null Decoded array, or null if not valid JSON.
     */
    public function toJson(): ?array
    {
        $cleaned = trim($this->content);

        // Strip markdown code fences if present
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        }

        $decoded = json_decode($cleaned, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}

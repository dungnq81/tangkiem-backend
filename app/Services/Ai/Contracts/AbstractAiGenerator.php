<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Log;

/**
 * Base class for AI content generators.
 *
 * Provides shared infrastructure:
 * - Provider-aware Google Search grounding
 * - Robust JSON parsing (simple: strip markdown + fallback regex)
 * - NOT_FOUND detection for internet search failures
 *
 * Subclasses implement the specific prompt building and result parsing logic.
 *
 * NOTE: Does NOT use AiService::parseJsonResponse() because that method
 * has auto-unwrapping logic (for AiExtractor) that would destroy
 * structured generator results like {bio, description, social_links}.
 */
abstract class AbstractAiGenerator
{
    /** Sentinel value the AI returns when it cannot find info online. */
    protected const NOT_FOUND_MARKER = 'NOT_FOUND';

    public function __construct(
        protected AiService $aiService,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // Shared Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Determine whether to use Google Search grounding.
     *
     * Combines the caller's condition (e.g., "no chapters") with a provider
     * check. Only returns true when BOTH conditions are met:
     * 1. The caller actually needs internet search
     * 2. The current provider is Gemini (only one supporting search grounding)
     *
     * This prevents silently switching to Gemini and wasting its quota
     * when the user has selected a different provider.
     */
    protected function shouldUseSearch(bool $needsSearch): bool
    {
        return $needsSearch && $this->aiService->supportsSearchGrounding();
    }

    /**
     * Check if the AI response indicates it couldn't find information.
     */
    protected function isNotFound(string $result): bool
    {
        return str_contains($result, static::NOT_FOUND_MARKER);
    }

    /**
     * Parse a text response as JSON with robust fallback extraction.
     *
     * Simple parsing pipeline:
     * 1. Strip markdown code block wrappers (```json ... ```)
     * 2. Try json_decode directly
     * 3. Fallback: extract JSON object via regex /\{.*\}/s
     * 4. Throw RuntimeException on failure
     *
     * Does NOT auto-unwrap or restructure the parsed data — the result
     * is returned exactly as the AI structured it. This is intentional:
     * AiService::parseJsonResponse() has unwrapping logic designed for
     * AiExtractor that would strip structured generator
     * results like {bio, description, social_links}.
     *
     * @param  string  $result  Raw AI response text
     * @return array<string, mixed>  Parsed JSON data
     *
     * @throws \RuntimeException When JSON parsing fails after all attempts
     */
    protected function parseJson(string $result): array
    {
        $text = trim($result);

        // Early bail for empty response
        if ($text === '') {
            Log::warning('AI Generator: Empty response from AI');
            throw new \RuntimeException('AI trả về kết quả rỗng. Vui lòng thử lại.');
        }

        // Strip markdown code block wrappers (handles ```json ... ``` and ``` ... ```)
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        // Fallback 1: Gemini sometimes puts literal newlines inside JSON string values
        // (e.g., "content": "\n<p>..." where \n is an actual newline, not escaped).
        // Replace literal newlines with spaces — safe for HTML content fields.
        if (! is_array($data)) {
            $fixedText = str_replace(["\r\n", "\r", "\n"], ' ', $text);
            $data = json_decode($fixedText, true);
        }

        // Fallback 2: extract JSON object from mixed text (AI may wrap JSON in explanatory text)
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $matches)) {
            $extracted = str_replace(["\r\n", "\r", "\n"], ' ', $matches[0]);
            $data = json_decode($extracted, true);
        }

        if (! is_array($data)) {
            Log::warning('AI Generator: Failed to parse JSON response', [
                'raw'   => mb_substr($result, 0, 500),
                'error' => json_last_error_msg(),
            ]);

            throw new \RuntimeException('AI trả về kết quả không hợp lệ. Vui lòng thử lại.');
        }

        return $data;
    }
}

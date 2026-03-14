<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\Ai\Client\AiClientFactory;
use App\Services\Ai\Client\ImageGenerationInterface;
use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared AI service — single entry point for all AI API calls.
 *
 * Orchestrates provider resolution, retry logic, and fallback.
 * Delegates actual API communication to driver clients via AiClientFactory.
 *
 * Features:
 * - Scraper (AiExtractor)
 * - AI Categorizer (story classification)
 * - AI Summarizer (story description)
 * - AI Content Cleaner (chapter cleaning)
 * - AI Moderator (comment moderation)
 * - AI Cover Generator (image generation)
 *
 * @see \App\Services\Ai\Client\AiClientFactory
 */
class AiService
{
    private const MAX_RETRIES = 3;

    /**
     * Fallback provider order when primary fails.
     * Only providers with API keys configured will be attempted.
     */
    private const FALLBACK_PROVIDERS = ['gemini', 'groq', 'cerebras', 'deepseek', 'openai', 'grok', 'anthropic', 'abacus', 'blackbox', 'aimagicx'];

    /**
     * Fallback provider order for image generation.
     * Only providers implementing ImageGenerationInterface will be attempted.
     */
    private const IMAGE_FALLBACK_PROVIDERS = ['aimagicx'];

    // ═══════════════════════════════════════════════════════════════
    // Feature Toggle
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check if an AI feature is enabled via Settings.
     *
     * Requires both global AI toggle AND specific feature toggle.
     */
    public static function isEnabled(string $feature): bool
    {
        return (bool) Setting::get('ai.enabled', false)
            && (bool) Setting::get("ai.{$feature}", false);
    }

    // ═══════════════════════════════════════════════════════════════
    // Public API — Text / JSON / Image
    // ═══════════════════════════════════════════════════════════════

    /**
     * Call AI and return plain text response.
     *
     * Use for: summary generation, content cleaning, translation.
     * When useSearch is true, forces Gemini provider (Google Search grounding is Gemini-only).
     *
     * @param  array  $extra  Extra options merged into call: timeout, maxRetries, skipFallback
     */
    public function callText(
        string $systemPrompt,
        string $userPrompt,
        ?string $provider = null,
        ?string $model = null,
        float $temperature = 0.3,
        bool $useSearch = false,
        ?string $responseMimeType = null,
        array $extra = [],
    ): string {
        [$provider, $model, $config] = $this->resolveProvider($provider, $model);

        // Google Search grounding only works with Gemini — auto-fallback
        // Must force Gemini's default model to avoid using Settings model (which belongs to the original provider)
        if ($useSearch && $provider !== 'gemini') {
            Log::info("AI search requested but provider is [{$provider}], falling back to Gemini.");
            $geminiDefaultModel = config('ai.providers.gemini.default_model', 'gemini-2.5-flash-lite');
            [$provider, $model, $config] = $this->resolveProvider('gemini', $geminiDefaultModel);
        }

        Log::debug('AI callText starting', [
            'provider' => $provider,
            'model' => $model,
            'system_len' => mb_strlen($systemPrompt),
            'user_len' => mb_strlen($userPrompt),
            'use_search' => $useSearch,
        ]);

        $options = ['temperature' => $temperature, ...$extra];
        if ($responseMimeType) {
            $options['responseMimeType'] = $responseMimeType;
        }
        if ($useSearch) {
            $options['useSearch'] = true;
        }

        $response = $this->callWithRetry(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            options: $options,
        );

        return $response->content;
    }

    /**
     * Call AI and return parsed JSON response as array.
     *
     * Use for: data extraction, categorization, moderation.
     *
     * @param  array  $extra  Extra options merged into call: timeout, maxRetries, skipFallback
     */
    public function callJson(
        string $systemPrompt,
        string $userPrompt,
        ?string $provider = null,
        ?string $model = null,
        float $temperature = 0.1,
        array $extra = [],
    ): array {
        [$provider, $model, $config] = $this->resolveProvider($provider, $model);

        Log::debug('AI callJson starting', [
            'provider' => $provider,
            'model' => $model,
            'system_len' => mb_strlen($systemPrompt),
            'user_len' => mb_strlen($userPrompt),
        ]);

        $options = [
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object'],
            'responseMimeType' => 'application/json',
            ...$extra,
        ];

        $response = $this->callWithRetry(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            options: $options,
        );

        return $this->parseJsonResponse($response->content);
    }

    /**
     * Generate image — primary: Gemini, fallback: providers implementing ImageGenerationInterface.
     *
     * Strategy:
     * 1. Try Gemini (uses generateContent with responseModalities: ['Image'])
     * 2. If Gemini fails → try IMAGE_FALLBACK_PROVIDERS (currently: AI Magicx)
     * 3. If all fail → throw RuntimeException
     *
     * @param  string       $prompt      Text prompt for image generation
     * @param  string|null  $aspectRatio Preset name (cover, thumbnail, banner, wide, portrait,
     *                                   landscape) or direct ratio (1:1, 2:3, 16:9, etc).
     *                                   Null uses config default.
     * @return string Base64-encoded image data
     */
    public function callImage(
        string $prompt,
        ?string $aspectRatio = null,
    ): string {
        $aspectRatio = $this->resolveAspectRatio($aspectRatio);

        // ── Try Gemini first (primary image provider) ──
        $geminiError = null;
        $config = config('ai.providers.gemini');

        if ($config && ! empty($config['api_key'])) {
            try {
                return $this->callGeminiImage($prompt, $aspectRatio, $config);
            } catch (\Throwable $e) {
                $geminiError = $e;
                Log::warning('AI callImage: Gemini failed, trying fallback providers', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('AI callImage: Gemini not configured, trying fallback providers');
        }

        // ── Try fallback image providers ──
        $fallbackResult = $this->tryImageFallbackProviders($prompt, $aspectRatio);

        if ($fallbackResult !== null) {
            return $fallbackResult;
        }

        // All providers failed
        throw new \RuntimeException(
            'Image generation failed. '
            . ($geminiError ? 'Gemini: ' . $geminiError->getMessage() : 'Gemini not configured.')
            . ' Fallback providers also failed or not configured.'
        );
    }

    /**
     * Generate image via Gemini (primary image provider).
     *
     * Uses the standard generateContent endpoint with responseModalities: ['Image'].
     * Auto-retries on 429 (rate limit) errors with backoff.
     *
     * @return string Base64-encoded image data
     */
    protected function callGeminiImage(string $prompt, string $aspectRatio, array $config): string
    {
        $imageModel = config('ai.imagen.model', 'gemini-2.5-flash-image');
        $url = "{$config['base_url']}/models/{$imageModel}:generateContent?key={$config['api_key']}";

        Log::debug('AI callImage starting (Gemini)', [
            'model'        => $imageModel,
            'prompt_len'   => mb_strlen($prompt),
            'aspect_ratio' => $aspectRatio,
        ]);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['Image'],
                'imageConfig' => [
                    'aspectRatio' => $aspectRatio,
                ],
            ],
        ];

        $maxAttempts = 3;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $lastResponse = Http::timeout(90)->post($url, $payload);

            if ($lastResponse->successful()) {
                break;
            }

            // Retry on 429 (rate limit) — wait and try again
            if ($lastResponse->status() === 429 && $attempt < $maxAttempts) {
                $waitSeconds = $this->extractRetryDelay($lastResponse->body());
                Log::warning("AI callImage: Rate limited (429), waiting {$waitSeconds}s before retry {$attempt}/{$maxAttempts}");
                sleep($waitSeconds);
                continue;
            }

            // Non-retryable error
            throw new \RuntimeException(
                $this->formatApiError('Gemini Image', $lastResponse->status(), $lastResponse->body())
            );
        }

        $data = $lastResponse->json();
        $parts = $data['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                return $part['inlineData']['data'];
            }
        }

        throw new \RuntimeException('Gemini Image không trả về dữ liệu ảnh.');
    }

    /**
     * Try fallback providers for image generation.
     *
     * Iterates IMAGE_FALLBACK_PROVIDERS, creates clients via AiClientFactory,
     * and calls generateImage() on any that implement ImageGenerationInterface.
     *
     * @return string|null Base64-encoded image data, or null if all failed.
     */
    private function tryImageFallbackProviders(string $prompt, string $aspectRatio): ?string
    {
        foreach (self::IMAGE_FALLBACK_PROVIDERS as $provider) {
            $config = config("ai.providers.{$provider}");
            if (! $config || empty($config['api_key'])) {
                continue;
            }

            $model = $config['default_model'] ?? null;
            if (! $model) {
                continue;
            }

            try {
                $client = AiClientFactory::make($provider, $model);

                if (! ($client instanceof ImageGenerationInterface)) {
                    continue;
                }

                Log::info("AI callImage fallback: trying {$provider}");

                return $client->generateImage($prompt, [
                    'aspectRatio' => $aspectRatio,
                ]);
            } catch (\Throwable $e) {
                Log::warning("AI callImage fallback {$provider} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Extract retry delay from Gemini 429 error response.
     *
     * Looks for "Please retry in Xs" pattern. Falls back to 60s.
     */
    protected function extractRetryDelay(string $responseBody): int
    {
        if (preg_match('/retry in ([\d.]+)s/i', $responseBody, $matches)) {
            return (int) ceil((float) $matches[1]);
        }

        return 60; // safe default
    }

    /**
     * Format a user-friendly error message from AI API error response.
     *
     * @param  string  $feature  Provider or feature name (e.g., 'Gemini', 'Groq', 'Image')
     */
    protected function formatApiError(string $feature, int $status, string $body): string
    {
        // Try to extract message from JSON error response
        $json = json_decode($body, true);
        $apiMessage = $json['error']['message'] ?? null;

        // Map common status codes to Vietnamese messages
        $message = match ($status) {
            429 => 'Vượt giới hạn API. Vui lòng thử lại sau ít phút.',
            401 => 'API key không hợp lệ hoặc đã hết hạn.',
            403 => 'Không có quyền truy cập API.',
            500, 502, 503 => 'Lỗi server. Vui lòng thử lại sau.',
            413 => 'Nội dung quá lớn cho model. Thử giảm kích thước dữ liệu.',
            default => $apiMessage
                ? Str::limit($apiMessage, 120)
                : "Lỗi API ({$status}). Vui lòng thử lại.",
        };

        // For 429, append wait time if available
        if ($status === 429 && preg_match('/retry in ([\d.]+)s/i', $body, $matches)) {
            $wait = (int) ceil((float) $matches[1]);
            $message = "Vượt giới hạn API. Thử lại sau {$wait} giây.";
        }

        return "{$feature}: {$message}";
    }

    /**
     * Resolve aspect ratio from preset name or direct ratio string.
     *
     * Accepts: preset name (cover, thumbnail, banner, etc.) or direct ratio (1:1, 16:9, etc.)
     */
    protected function resolveAspectRatio(?string $input): string
    {
        $validRatios = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];

        if (! $input) {
            return config('ai.imagen.default_aspect_ratio', '2:3');
        }

        // Check if it's a preset name (cover, thumbnail, banner, etc.)
        $presets = config('ai.imagen.aspect_ratios', []);
        if (isset($presets[$input])) {
            return $presets[$input];
        }

        // Direct ratio value — validate
        if (in_array($input, $validRatios, true)) {
            return $input;
        }

        Log::warning("Invalid aspect ratio '{$input}', falling back to default.");

        return config('ai.imagen.default_aspect_ratio', '2:3');
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal — Provider Resolution
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve provider config from name, with fallback to settings/defaults.
     *
     * Fallback chain: explicit param → global AI setting → config default.
     * Empty strings are treated as "use default" (from Select dropdowns).
     *
     * @return array{0: string, 1: string, 2: array} [provider, model, config]
     */
    protected function resolveProvider(?string $provider = null, ?string $model = null): array
    {
        $provider = $provider ?: (Setting::get('ai.provider') ?: 'gemini');

        $config = config("ai.providers.{$provider}");

        if (! $config) {
            throw new \RuntimeException("AI provider [{$provider}] is not configured.");
        }

        $apiKey = $config['api_key'] ?? null;
        if (! $apiKey && $provider !== 'ollama') {
            throw new \RuntimeException("API key for [{$provider}] is not set. Check your .env file.");
        }

        $model = $model ?: (Setting::get('ai.model') ?: ($config['default_model'] ?? null));

        if (! $model) {
            throw new \RuntimeException("No AI model specified for [{$provider}].");
        }

        return [$provider, $model, $config];
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal — API Calls with Retry (Driver-based)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Call AI API with retry, 429-aware backoff, and provider fallback.
     *
     * Uses AiClientFactory to resolve provider → driver, then delegates
     * the actual HTTP call to the driver's chat() method.
     *
     * Retry strategy:
     * - Attempt up to maxRetries with the primary provider (default: MAX_RETRIES)
     * - On 429 (rate limit): extract "retry in Xs" delay, wait that long
     * - On other errors: exponential backoff (1s, 2s, 3s)
     * - If all retries fail: try fallback providers (unless skipFallback or useSearch)
     *
     * Options:
     * - maxRetries: int — override MAX_RETRIES for this call
     * - skipFallback: bool — skip fallback providers on failure
     */
    protected function callWithRetry(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): AiResponse {
        $useSearch = $options['useSearch'] ?? false;
        $skipFallback = $options['skipFallback'] ?? false;
        $maxRetries = $options['maxRetries'] ?? self::MAX_RETRIES;

        // Try primary provider first
        ['result' => $result, 'error' => $lastError] = $this->attemptCalls(
            $provider, $model,
            $systemPrompt, $userPrompt, $options,
            $maxRetries,
        );

        if ($result !== null) {
            return $result;
        }

        // Try fallback providers (skip if useSearch, skipFallback, or explicitly disabled)
        if (! $useSearch && ! $skipFallback) {
            $fallbackResult = $this->tryFallbackProviders(
                $provider, $systemPrompt, $userPrompt, $options,
            );

            if ($fallbackResult !== null) {
                return $fallbackResult;
            }
        }

        throw new \RuntimeException(
            'AI call failed after ' . $maxRetries . ' attempts: ' . $lastError?->getMessage()
        );
    }

    /**
     * Attempt calls to a specific provider with retry logic.
     *
     * Creates a client via AiClientFactory and calls chat().
     *
     * @param  int  $maxRetries  Maximum retries (default: MAX_RETRIES)
     * @return array{result: AiResponse|null, error: \Throwable|null}
     */
    private function attemptCalls(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $options,
        int $maxRetries = self::MAX_RETRIES,
    ): array {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $client = AiClientFactory::make($provider, $model);
                $response = $client->chat($systemPrompt, $userPrompt, $options);

                return ['result' => $response, 'error' => null];
            } catch (\Throwable $e) {
                $lastError = $e;
                $errorMsg = $e->getMessage();

                Log::warning("AI call attempt {$attempt}/{$provider} failed", [
                    'provider' => $provider,
                    'model'    => $model,
                    'error'    => $errorMsg,
                ]);

                if ($attempt < $maxRetries) {
                    // 429 rate limit: extract and respect server's retry-after delay
                    if (
                        ($e instanceof AiClientException && $e->isRateLimited())
                        || str_contains($errorMsg, '429')
                        || stripos($errorMsg, 'rate limit') !== false
                    ) {
                        $delay = $this->extractRetryDelay($errorMsg);
                        Log::info("AI 429 detected, waiting {$delay}s before retry");
                        sleep(min($delay, 120)); // Cap at 2 min
                    } else {
                        // Other errors: exponential backoff 1s, 2s, 3s
                        sleep($attempt);
                    }
                }
            }
        }

        return ['result' => null, 'error' => $lastError];
    }

    /**
     * Try fallback providers when primary fails.
     *
     * Iterates through FALLBACK_PROVIDERS, skipping:
     * - The already-failed primary provider
     * - Providers without API keys
     *
     * Uses each provider's default model.
     */
    private function tryFallbackProviders(
        string $failedProvider,
        string $systemPrompt,
        string $userPrompt,
        array $options,
    ): ?AiResponse {
        foreach (self::FALLBACK_PROVIDERS as $fallbackProvider) {
            if ($fallbackProvider === $failedProvider) {
                continue;
            }

            $fallbackConfig = config("ai.providers.{$fallbackProvider}");
            if (! $fallbackConfig || empty($fallbackConfig['api_key'])) {
                continue;
            }

            $fallbackModel = $fallbackConfig['default_model'] ?? null;
            if (! $fallbackModel) {
                continue;
            }

            Log::info("AI fallback: trying {$fallbackProvider}/{$fallbackModel}");

            try {
                $client = AiClientFactory::make($fallbackProvider, $fallbackModel);

                return $client->chat($systemPrompt, $userPrompt, $options);
            } catch (\Throwable $e) {
                Log::warning("AI fallback {$fallbackProvider} also failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal — JSON Parsing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Parse JSON from AI response text.
     *
     * Handles edge cases: markdown code blocks, wrapper objects, etc.
     *
     * @return array<string, mixed>
     */
    protected function parseJsonResponse(string $responseText): array
    {
        $text = trim($responseText);

        // Remove markdown code block wrappers if present
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON array from text
            if (preg_match('/\[.*\]/s', $text, $matches)) {
                $decoded = json_decode($matches[0], true);
            }

            // Try to extract JSON object from text
            if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.*\}/s', $text, $matches)) {
                $decoded = json_decode($matches[0], true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('AI response JSON parse failed', [
                    'error' => json_last_error_msg(),
                    'response' => mb_substr($text, 0, 500),
                ]);
                throw new \RuntimeException('Failed to parse AI response as JSON: ' . json_last_error_msg());
            }
        }

        // If response is a wrapper object with an array inside, extract it
        // e.g. {"items": [...]} or {"stories": [...]} or {"data": [...]}
        if (is_array($decoded) && ! array_is_list($decoded)) {
            // Check if all values are non-array (simple key-value object) — return as-is
            $hasArrayValue = false;
            foreach ($decoded as $value) {
                if (is_array($value) && (empty($value) || array_is_list($value))) {
                    $hasArrayValue = true;
                    break;
                }
            }

            // If it looks like a structured response (not just a wrapper), return as-is
            // e.g. {"categories": [...], "tags": [...], "type": "novel"}
            $nonArrayKeys = 0;
            foreach ($decoded as $value) {
                if (! is_array($value)) {
                    $nonArrayKeys++;
                }
            }

            // If object has mixed keys (arrays + scalars), it's a structured response
            if ($nonArrayKeys > 0 && $hasArrayValue) {
                return $decoded;
            }

            // If object has only one array value, unwrap it (legacy AiExtractor behavior)
            if ($hasArrayValue && count($decoded) === 1) {
                foreach ($decoded as $value) {
                    if (is_array($value) && (empty($value) || array_is_list($value))) {
                        return $value;
                    }
                }
            }
        }

        return is_array($decoded) ? $decoded : [$decoded];
    }
}

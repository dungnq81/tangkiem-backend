<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;

/**
 * AI Client Interface — Contract for all AI provider drivers.
 *
 * Each driver handles a specific API format:
 * - OpenAiClient    → /v1/chat/completions (OpenAI, Groq, Cerebras, DeepSeek, Grok)
 * - GeminiClient    → /models/{model}:generateContent (Google Gemini)
 * - AnthropicClient → /v1/messages (Anthropic Claude)
 */
interface AiClientInterface
{
    /**
     * @param  string  $provider  Provider slug (e.g., 'gemini', 'groq').
     * @param  string  $model     Model ID (e.g., 'gemini-2.5-flash-lite').
     * @param  string  $apiKey    API key.
     * @param  string  $baseUrl   Base URL for the provider API.
     */
    public function __construct(
        string $provider,
        string $model,
        string $apiKey,
        string $baseUrl,
    );

    /**
     * Send a chat completion request.
     *
     * @param  string  $systemPrompt  System/instruction prompt.
     * @param  string  $userMessage   User message content.
     * @param  array   $options       Optional: temperature, max_tokens, response_format,
     *                                timeout, useSearch (Gemini only).
     *
     * @return AiResponse
     *
     * @throws AiClientException On API error or network failure.
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse;

    /**
     * Get the provider slug.
     */
    public function getProvider(): string;

    /**
     * Get the model ID being used.
     */
    public function getModel(): string;
}

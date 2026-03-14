<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini Client — Handles Google Generative Language API.
 *
 * Uses the Gemini REST API format (not OpenAI-compatible).
 * Endpoint: /models/{model}:generateContent?key={apiKey}
 *
 * Supports Google Search grounding via `useSearch` option.
 */
class GeminiClient implements AiClientInterface
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'parts' => [['text' => $userMessage]],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.1,
            ],
        ];

        if (isset($options['max_tokens'])) {
            $body['generationConfig']['maxOutputTokens'] = $options['max_tokens'];
        }

        // Handle response format
        if (isset($options['response_format']['type']) && $options['response_format']['type'] === 'json_object') {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        } elseif (isset($options['responseMimeType'])) {
            $body['generationConfig']['responseMimeType'] = $options['responseMimeType'];
        }

        // Enable Google Search grounding for internet research
        if (! empty($options['useSearch'])) {
            $body['tools'] = [['googleSearch' => new \stdClass()]];
        }

        $url = rtrim($this->baseUrl, '/')
            . '/models/' . $this->model . ':generateContent'
            . '?key=' . $this->apiKey;

        $timeout = $options['timeout'] ?? 60;

        try {
            $response = Http::timeout($timeout)->post($url, $body);
        } catch (\Throwable $e) {
            throw new AiClientException(
                "Network error: {$e->getMessage()}",
                $this->provider,
                $this->model,
                0,
                $e,
            );
        }

        if ($response->failed()) {
            $data = $response->json();
            $errorMsg = $data['error']['message'] ?? "HTTP {$response->status()}";

            throw new AiClientException(
                $errorMsg,
                $this->provider,
                $this->model,
                $response->status(),
            );
        }

        $data = $response->json();
        $candidate = $data['candidates'][0] ?? [];
        $content = $candidate['content']['parts'][0]['text'] ?? '';
        $usage = $data['usageMetadata'] ?? [];

        return new AiResponse(
            content:      $content,
            provider:     $this->provider,
            model:        $data['modelVersion'] ?? $this->model,
            promptTokens: $usage['promptTokenCount'] ?? 0,
            outputTokens: $usage['candidatesTokenCount'] ?? 0,
            finishReason: strtolower($candidate['finishReason'] ?? 'stop'),
        );
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
